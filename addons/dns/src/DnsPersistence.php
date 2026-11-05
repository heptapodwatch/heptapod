<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DnsPersistence implements RunPersistence
{
    public function stage(CheckContext $context, string $dataset, array $rows): void
    {
        if ($dataset !== 'snapshot' || count($rows) !== 1) {
            throw new \InvalidArgumentException('DNS persistence requires one snapshot row.');
        }
        $data = Validator::make($rows[0], [
            'dns_records' => ['present', 'array', 'list', 'max:154000'],
            'raw_dns_records' => ['present', 'array:'.implode(',', DnsCheck::Types)],
            'raw_dns_records.*' => ['array', 'list', 'max:22000'],
            'raw_dns_records.*.*' => ['string', 'max:65535'],
            'normalized_records' => ['present', 'array', 'max:154'],
            'normalized_records.*' => ['array', 'list', 'max:1000'],
            'issues' => ['present', 'array', 'list', 'max:200'],
            'issues.*' => ['array:type,host,record_type,nameserver,reason'],
            'issues.*.type' => ['required', 'in:dns.issue.records_missing,dns.issue.resolves_to_nothing,dns.issue.nameservers_missing,dns.issue.nameservers_out_of_sync,dns.issue.nameserver_unreachable,dns.issue.nameservers_unreachable'],
            'issues.*.host' => ['required', 'string', 'max:253'],
            'issues.*.record_type' => ['present', 'nullable', 'in:'.implode(',', DnsCheck::Types)],
            'issues.*.nameserver' => ['sometimes', 'required', 'string', 'max:253'],
            'issues.*.reason' => ['sometimes', 'required', 'in:timeout,servfail'],
            'authoritative_nameservers' => ['present', 'array', 'list', 'max:1000'],
            'authoritative_nameservers.*' => ['required', 'string', 'max:253'],
            'baseline_generation' => ['required', 'integer:strict', 'min:0'],
            'auto_accept_changes' => ['sometimes', 'boolean:strict'],
            'expected' => ['present', 'array', 'list', 'max:154'],
            'expected.*' => ['required', 'string', 'distinct'],
        ])->validate();
        /** @var list<array<string, mixed>> $records */
        $records = $data['dns_records'];
        // The external seam may supply malformed records; validate before storing any dataset.
        foreach (array_chunk($records, 1000) as $batch) {
            DnsSnapshot::normalize($batch);
        }
        $expected = $data['expected'];
        $generation = $data['baseline_generation'];
        $autoAccept = $data['auto_accept_changes'] ?? false;
        unset($data['expected'], $data['baseline_generation'], $data['auto_accept_changes']);
        DB::transaction(function () use ($context, $data, $expected, $generation, $autoAccept): void {
            $existing = DB::table('dns_observations')->where('run_id', $context->runId)->where('claim_token', $context->claimToken)->lockForUpdate()->first();
            if ($existing !== null && $existing->run_ended_at !== null) {
                throw new \LogicException('Cannot stage an accepted DNS observation.');
            }
            DB::table('dns_observations')->updateOrInsert(['run_id' => $context->runId, 'claim_token' => $context->claimToken], [
                'site_id' => $context->siteId, 'check_id' => $context->checkId,
                'snapshot' => json_encode($data, JSON_THROW_ON_ERROR), 'expected' => json_encode($expected, JSON_THROW_ON_ERROR),
                'baseline_generation' => $generation, 'auto_accept_changes' => $autoAccept,
                'created_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function finalize(RunReference $run, string $claimToken): void
    {
        DB::transaction(function () use ($run, $claimToken): void {
            $query = DB::table('dns_observations')->where('run_id', $run->id)->where('claim_token', $claimToken);
            $row = $query->lockForUpdate()->first();
            // Resolver failures have no staged DNS observation; acceptance must preserve the baseline.
            if ($row === null) {
                return;
            }
            /** @var \stdClass&object{check_id: int, site_id: int, snapshot: string, expected: string, baseline_generation: int, auto_accept_changes: bool, run_ended_at: string|null} $row */
            if ($row->run_ended_at !== null) {
                if (! CarbonImmutable::parse($row->run_ended_at)->eq($run->endedAt)) {
                    throw new \LogicException('DNS attempt already accepted for a different timestamp.');
                }

                return;
            }
            $state = $this->baselineState($row->check_id);
            $ended = $run->endedAt->format('Y-m-d H:i:s.uP');
            $query->update(['run_ended_at' => $ended]);
            // Reset invalidates pre-reset comparison state, not the recorder's evaluation-time outcome.
            if ($row->baseline_generation !== $state['generation']) {
                return;
            }
            /** @var array{dns_records: list<array<string, int|string>>, raw_dns_records: array<string, list<string>>, normalized_records: array<string, list<array<string, int|string>>>, issues: list<array{type: string, host: string, record_type: string|null}>, authoritative_nameservers: list<string>} $data */
            $data = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $snapshot = new DnsSnapshot(...$data);
            $previous = $state['baseline'] === null ? null : DB::table('dns_observations')->where('check_id', $row->check_id)
                ->where('run_id', '<>', $run->id)->whereNotNull('run_ended_at')
                ->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['snapshot']);
            /** @var (\stdClass&object{snapshot: string})|null $previous */
            /** @var array{normalized_records: array<string, list<array<string, int|string>>>, issues: list<array<string, mixed>>, authoritative_nameservers: list<string>}|null $previousSnapshot */
            $previousSnapshot = $previous === null ? ($state['baseline']['snapshot'] ?? null) : json_decode($previous->snapshot, true, flags: JSON_THROW_ON_ERROR);
            // History records the actual address deltas, even when proxy rotation is equivalent for alerts.
            $diff = $previousSnapshot === null ? [] : $snapshot->changes($previousSnapshot['normalized_records'], ignoreProxyRotation: false);
            $changed = $previousSnapshot === null || DnsSnapshot::comparisonIdentity($data['normalized_records']) !== DnsSnapshot::comparisonIdentity($previousSnapshot['normalized_records']) || DnsSnapshot::comparisonIdentity($data['issues']) !== DnsSnapshot::comparisonIdentity($previousSnapshot['issues']) || $data['authoritative_nameservers'] !== $previousSnapshot['authoritative_nameservers'];
            if ($state['baseline'] === null || $row->auto_accept_changes) {
                DB::table('dns_baselines')->updateOrInsert(['check_id' => $row->check_id], [
                    'run_id' => $run->id, 'snapshot' => $row->snapshot, 'expected' => $row->expected,
                    'accepted_by' => null, 'accepted_by_name' => null, 'accepted_at' => null, 'accepted_pending_recheck' => false,
                    'warning_episode_run_id' => null,
                ]);
            } else {
                $acceptedChanges = $snapshot->changes($state['baseline']['snapshot']['normalized_records']);
                $marker = $state['warning_episode_run_id'];
                DB::table('dns_baselines')->where('check_id', $row->check_id)->update([
                    'accepted_pending_recheck' => false,
                    'warning_episode_run_id' => $acceptedChanges !== [] ? ($marker ?? $run->id) : ($data['issues'] === [] ? null : $marker),
                ]);
            }
            if ($changed) {
                DB::table('dns_history_items')->insert([
                    ...array_map(static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR), $data),
                    'check_id' => $row->check_id, 'site_id' => $row->site_id, 'run_id' => $run->id, 'run_ended_at' => $ended,
                    'initial' => $state['baseline'] === null && $previous === null, 'diff' => json_encode($diff, JSON_THROW_ON_ERROR),
                    'diff_summary' => implode(', ', array_map(static fn (string $type, array $change): string => $type.': +'.count($change['added']).' −'.count($change['removed']), array_keys($diff), array_values($diff))), 'created_at' => $ended,
                    'raw_dns_records' => json_encode((object) $data['raw_dns_records'], JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function comparison(int $checkId): DnsComparisonData
    {
        $baseline = DB::table('dns_baselines')->where('check_id', $checkId)->first(['run_id', 'snapshot', 'expected', 'accepted_at', 'accepted_by_name']);
        /** @phpstan-var (\stdClass&object{run_id: int, snapshot: string, expected: string, accepted_at: string|null, accepted_by_name: string|null})|null $baseline */
        $current = DB::table('dns_observations')->where('check_id', $checkId)->whereNotNull('run_ended_at')
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['run_id', 'snapshot']);
        /** @var (\stdClass&object{run_id: int, snapshot: string})|null $current */
        /** @var array{normalized_records: array<string, list<array<string, int|string>>>, dns_records: list<array<string, int|string>>, raw_dns_records: array<string, list<string>>, issues: list<array<string, mixed>>, authoritative_nameservers: list<string>}|null $previousSnapshot */
        $previousSnapshot = $baseline === null ? null : json_decode($baseline->snapshot, true, flags: JSON_THROW_ON_ERROR);
        /** @var array{normalized_records: array<string, list<array<string, int|string>>>, dns_records: list<array<string, int|string>>, raw_dns_records: array<string, list<string>>, issues: list<array{type: string, host: string, record_type: string|null}>, authoritative_nameservers: list<string>}|null $currentSnapshot */
        $currentSnapshot = $current === null ? null : json_decode($current->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $diff = $currentSnapshot === null || $previousSnapshot === null ? [] : DnsSnapshot::from($currentSnapshot)->changes($previousSnapshot['normalized_records']);
        $wouldChange = false;
        if ($currentSnapshot !== null && $previousSnapshot !== null && $baseline !== null) {
            $present = array_filter($currentSnapshot['normalized_records'], static fn (array $records): bool => $records !== []);
            /** @var list<string> $previousExpected */
            $previousExpected = json_decode($baseline->expected, true, flags: JSON_THROW_ON_ERROR);
            $expected = array_keys($present);
            sort($expected);
            sort($previousExpected);
            // Empty inherited types can still be cleared by accept after automatic acceptance stored identical records.
            $wouldChange = $diff !== []
                || $expected !== $previousExpected;
        }
        $truncated = false;
        $accepted = $this->boundedRecords($previousSnapshot['normalized_records'] ?? [], $truncated);
        $observed = $this->boundedRecords($currentSnapshot['normalized_records'] ?? [], $truncated);
        $remaining = 200;
        foreach ($diff as &$change) {
            foreach (['added', 'removed'] as $direction) {
                if (count($change[$direction]) > $remaining) {
                    $truncated = true;
                    $change[$direction] = array_slice($change[$direction], 0, $remaining);
                }
                $remaining -= count($change[$direction]);
            }
        }
        unset($change);
        $timeline = DB::table('dns_history_items')->where('check_id', $checkId)->orderByDesc('run_ended_at')->orderByDesc('run_id')
            ->limit(20)->get(['run_id', 'run_ended_at', 'diff_summary', 'initial']);
        $items = [];
        foreach ($timeline as $item) {
            /** @var \stdClass&object{run_id: int, run_ended_at: string, diff_summary: string, initial: bool} $item */
            $items[] = ['run_id' => $item->run_id, 'observed_at' => CarbonImmutable::parse($item->run_ended_at)->utc()->toIso8601String(),
                'diff_summary' => $item->diff_summary, 'initial' => $item->initial];
        }

        return new DnsComparisonData($baseline?->run_id, $baseline?->accepted_at === null ? null : CarbonImmutable::parse($baseline->accepted_at)->utc()->toIso8601String(), $baseline?->accepted_by_name,
            $wouldChange, $currentSnapshot['issues'] ?? [], $accepted, $current?->run_id, $observed, $diff, $truncated, $items);
    }

    /** @param array<string, list<array<string, int|string>>> $groups
     * @return array<string, list<array<string, int|string>>>
     */
    private function boundedRecords(array $groups, bool &$truncated): array
    {
        $bounded = [];
        $remaining = 200;
        foreach ($groups as $name => $records) {
            if ($remaining === 0) {
                $truncated = true;
                break;
            }
            foreach ($records as $record) {
                if ($remaining === 0) {
                    $truncated = true;
                    break 2;
                }
                foreach ($record as $field => $value) {
                    if (is_string($value) && mb_strlen($value) > 255) {
                        $record[$field] = mb_substr($value, 0, 255);
                        $truncated = true;
                    }
                }
                $bounded[$name][] = $record;
                $remaining--;
            }
        }

        return $bounded;
    }

    public function accept(int $checkId, int $actorId, string $actorName, int $expectedRunId): int
    {
        return DB::transaction(function () use ($checkId, $actorId, $actorName, $expectedRunId): int {
            $state = $this->baselineState($checkId);
            $latest = DB::table('dns_observations')->where('check_id', $checkId)
                ->where('baseline_generation', $state['generation'])->whereNotNull('run_ended_at')
                ->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['run_id', 'snapshot', 'expected']);
            if ($latest === null) {
                throw ValidationException::withMessages(['check' => __('No accepted DNS observation is available.')]);
            }
            /** @var \stdClass&object{run_id: int, snapshot: string, expected: string} $latest */
            if ($latest->run_id !== $expectedRunId) {
                throw ValidationException::withMessages(['accepted_run_id' => __('There are newer DNS results — please review again.')]);
            }
            /** @var array{normalized_records: array<string, list<array<string, int|string>>>, issues: list<array{type: string, host: string, record_type: string|null}>} $snapshot */
            $snapshot = json_decode($latest->snapshot, true, flags: JSON_THROW_ON_ERROR);
            /** @var list<string> $observedExpected */
            $observedExpected = json_decode($latest->expected, true, flags: JSON_THROW_ON_ERROR);
            $expected = [];
            foreach ($observedExpected as $key) {
                if (! array_key_exists($key, $snapshot['normalized_records'])) {
                    throw new \LogicException('Expected DNS key is missing from the observation: '.$key);
                }
                if ($snapshot['normalized_records'][$key] !== []) {
                    $expected[] = $key;
                }
            }
            $snapshot['issues'] = array_values(array_filter($snapshot['issues'], static fn (array $issue): bool => $issue['type'] !== 'dns.issue.records_missing'));
            DB::table('dns_baselines')->updateOrInsert(['check_id' => $checkId], [
                'run_id' => $latest->run_id, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'expected' => json_encode($expected, JSON_THROW_ON_ERROR),
                'accepted_by' => $actorId, 'accepted_by_name' => mb_substr($actorName, 0, 255),
                'accepted_at' => now('UTC')->format('Y-m-d H:i:s.uP'), 'accepted_pending_recheck' => true,
                'warning_episode_run_id' => null,
            ]);
            DB::table('dns_baseline_generations')->where('check_id', $checkId)->increment('generation');

            return $latest->run_id;
        });
    }

    /** @return array{run_id: int, expected: list<string>, snapshot: array{normalized_records: array<string, list<array<string, int|string>>>, issues: list<array{type: string, host: string, record_type: string|null}>, authoritative_nameservers: list<string>}}|null */
    public function baseline(int $checkId): ?array
    {
        $row = DB::table('dns_baselines')->where('check_id', $checkId)->first();
        if ($row === null) {
            return null;
        }
        /** @var \stdClass&object{run_id: int, snapshot: string, expected: string} $row */
        /** @var array{normalized_records: array<string, list<array<string, int|string>>>, issues: list<array{type: string, host: string, record_type: string|null}>, authoritative_nameservers: list<string>} $snapshot */
        $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
        /** @var list<string> $expected */
        $expected = json_decode($row->expected, true, flags: JSON_THROW_ON_ERROR);

        return ['run_id' => $row->run_id, 'snapshot' => $snapshot, 'expected' => $expected];
    }

    /** @return array{generation: int, warning_episode_run_id: int|null, baseline: array{run_id: int, expected: list<string>, snapshot: array{normalized_records: array<string, list<array<string, int|string>>>, issues: list<array{type: string, host: string, record_type: string|null}>, authoritative_nameservers: list<string>}}|null} */
    public function baselineState(int $checkId): array
    {
        return DB::transaction(function () use ($checkId): array {
            DB::table('dns_baseline_generations')->insertOrIgnore(['check_id' => $checkId, 'generation' => 0]);
            // Reset and accepted finalization serialize on this retained row, even with no baseline.
            /** @var int $generation */
            $generation = DB::table('dns_baseline_generations')->where('check_id', $checkId)->lockForUpdate()->soleValue('generation');

            /** @var int|null $warningEpisodeRunId */
            $warningEpisodeRunId = DB::table('dns_baselines')->where('check_id', $checkId)->value('warning_episode_run_id');

            return ['generation' => $generation, 'warning_episode_run_id' => $warningEpisodeRunId, 'baseline' => $this->baseline($checkId)];
        });
    }

    public function discard(int $runId, string $claimToken): void
    {
        DB::table('dns_observations')->where('run_id', $runId)->where('claim_token', $claimToken)->whereNull('run_ended_at')->delete();
    }

    public function prune(CarbonImmutable $before): void
    {
        // Baselines and reset generations outlive raw retention so old attempts cannot become current again.
        $bound = $before->format('Y-m-d H:i:s.uP');
        DB::table('dns_observations')->where('run_ended_at', '<', $bound)->delete();
        DB::table('dns_history_items')->where('run_ended_at', '<', $bound)->delete();
    }
}

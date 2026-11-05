<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class DomainPersistence implements RunPersistence
{
    public function stage(CheckContext $context, string $dataset, array $rows): void
    {
        if ($dataset !== 'snapshot' || count($rows) !== 1) {
            throw new \InvalidArgumentException('Domain persistence requires one snapshot row.');
        }
        $snapshot = Validator::make($rows[0], [
            'domain' => ['required', 'string', 'max:253'],
            'registrar' => ['present', 'nullable', 'string', 'max:1000'],
            'expires_at' => ['present', 'nullable', 'date'],
            'registered_at' => ['present', 'nullable', 'date'],
            'last_changed_at' => ['present', 'nullable', 'date'],
            'last_updated_in_rdap_db_at' => ['present', 'nullable', 'date'],
            'created_at' => ['required', 'date'],
            'nameservers' => ['present', 'array', 'list', 'max:100'],
            'nameservers.*' => ['string', 'max:253'],
            'domain_statuses' => ['present', 'array', 'max:100'],
            'domain_statuses.*' => ['boolean:strict'],
            'rdap_domain_response' => ['present', 'nullable', 'array'],
            'date_precision' => ['required', 'in:exact,day'],
            'source' => ['required', 'in:rdap,whois'],
            'server' => ['required', 'string', 'max:2000'],
            'not_supported_reason' => ['present', 'nullable', 'in:registry_does_not_publish,lookup_unavailable'],
        ])->validate();
        $snapshot['domain_statuses'] = (object) $snapshot['domain_statuses'];
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        if (strlen($json) > 270336) {
            throw new \InvalidArgumentException('Domain snapshot exceeds its storage bound.');
        }
        DB::transaction(function () use ($context, $json): void {
            $existing = DB::table('domain_snapshots')->where('run_id', $context->runId)->where('claim_token', $context->claimToken)->lockForUpdate()->first();
            if ($existing !== null && $existing->run_ended_at !== null) {
                throw new \LogicException('Cannot stage an accepted domain snapshot.');
            }
            DB::table('domain_snapshots')->updateOrInsert(['run_id' => $context->runId, 'claim_token' => $context->claimToken], [
                'site_id' => $context->siteId, 'check_id' => $context->checkId,
                'snapshot' => $json, 'created_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function finalize(RunReference $run, string $claimToken): void
    {
        DB::transaction(function () use ($run, $claimToken): void {
            $query = DB::table('domain_snapshots')->where('run_id', $run->id)->where('claim_token', $claimToken);
            $row = $query->lockForUpdate()->first();
            // Lookup errors have no staged observation and must not replace the baseline.
            if ($row === null) {
                return;
            }
            /** @var \stdClass&object{run_ended_at: string|null} $row */
            if ($row->run_ended_at !== null) {
                if (! CarbonImmutable::parse($row->run_ended_at)->eq($run->endedAt)) {
                    throw new \LogicException('Domain attempt finalized for a different timestamp.');
                }

                return;
            }
            $query->update(['run_ended_at' => $run->endedAt->format('Y-m-d H:i:s.uP')]);
        });
    }

    /** @return array{run_id: int, snapshot: array<string, mixed>}|null */
    public function latest(int $checkId): ?array
    {
        $row = DB::table('domain_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['run_id', 'snapshot']);
        if ($row === null) {
            return null;
        }
        /** @var \stdClass&object{run_id: int, snapshot: string} $row */
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);

        return ['run_id' => $row->run_id, 'snapshot' => $snapshot];
    }

    public function prune(CarbonImmutable $before): void
    {
        // The last accepted observation survives errors and raw-run retention.
        $baselines = DB::table('domain_snapshots')->selectRaw('DISTINCT ON (check_id) id')
            ->whereNotNull('run_ended_at')->orderBy('check_id')->orderByDesc('run_ended_at')->orderByDesc('run_id');
        DB::table('domain_snapshots')->where('run_ended_at', '<', $before->format('Y-m-d H:i:s.uP'))->whereNotIn('id', $baselines)->delete();
    }

    public function discard(int $runId, string $claimToken): void
    {
        DB::table('domain_snapshots')->where('run_id', $runId)->where('claim_token', $claimToken)->whereNull('run_ended_at')->delete();
    }
}

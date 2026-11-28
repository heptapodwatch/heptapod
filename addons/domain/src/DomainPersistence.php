<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\HistoryPrune;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\StagedSnapshots;
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
        StagedSnapshots::stage('domain_snapshots', $context, [
            'site_id' => $context->siteId, 'check_id' => $context->checkId,
            'snapshot' => $json, 'created_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function finalize(RunReference $run, string $claimToken): void
    {
        // Lookup errors have no staged observation and must not replace the baseline.
        StagedSnapshots::finalize('domain_snapshots', $run, $claimToken);
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

    public function purgeCheck(int $checkId, int $limit): int
    {
        return StagedSnapshots::purge('domain_snapshots', $checkId, $limit);
    }

    public function prunedTables(): array
    {
        return ['domain_snapshots'];
    }

    public function historyPrunes(): array
    {
        // The last accepted observation survives errors and raw-run retention.
        return [new HistoryPrune('domain_snapshots', baselinePer: 'check_id')];
    }

    public function discard(int $runId, string $claimToken): void
    {
        StagedSnapshots::discard('domain_snapshots', $runId, $claimToken);
    }
}

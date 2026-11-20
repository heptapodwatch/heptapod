<?php

declare(strict_types=1);

namespace Heptapod\Addons\ApplicationHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\ListPaging;
use Heptapod\Contracts\PaginationData;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\SecurityAdvisoryIndex;
use Heptapod\Contracts\SecurityAdvisoryPackageData;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class ApplicationHealthPersistence implements RunPersistence, SecurityAdvisoryIndex
{
    private const array SeverityRanks = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];

    public function __construct(private ListPaging $paging) {}

    public function affectedChecks(?string $package = null, ?string $advisoryId = null): Builder
    {
        return DB::table('application_health_advisories')->select('check_id')
            ->when($package !== null, static fn (Builder $query): Builder => $query->where('package', $package))
            ->when($advisoryId !== null, static fn (Builder $query): Builder => $query->where('advisory_id', $advisoryId));
    }

    public function packages(Builder $checkIds, int $limit): array
    {
        $rank = 'CASE severity';
        foreach (self::SeverityRanks as $severity => $value) {
            $rank .= " WHEN '{$severity}' THEN {$value}";
        }
        // A site has at most one application health check, so distinct checks count sites.
        $rows = DB::table('application_health_advisories')->whereIn('check_id', $checkIds)
            ->groupBy('package')
            ->selectRaw('package, count(DISTINCT check_id)::int AS sites, max('.$rank.' ELSE 0 END)::int AS severity_rank')
            ->orderByDesc('severity_rank')->orderByDesc('sites')->orderBy('package')->limit($limit)->get()->all();

        return array_values(array_map(static function (object $row): SecurityAdvisoryPackageData {
            /** @var \stdClass&object{package: string, sites: int, severity_rank: int} $row */
            $severity = array_search($row->severity_rank, self::SeverityRanks, true);

            return new SecurityAdvisoryPackageData($row->package, $row->sites, $severity === false ? null : $severity);
        }, $rows));
    }

    /**
     * Identities are immediate; snapshots become baselines only after recorder acceptance.
     *
     * @param  list<string>  $names
     * @return array<string, array{id: int, change_identity: string|null, run_id: int|null, snapshot: array{status: string, latest_result?: string, unresolved_result?: ?string}|null}>
     */
    public function components(int $checkId, array $names): array
    {
        if ($names !== []) {
            DB::table('application_health_components')->insertOrIgnore(array_map(static fn (string $name): array => [
                'check_id' => $checkId, 'source_key' => hash('sha256', $name), 'detected_at' => now()->format('Y-m-d H:i:s.uP'),
            ], $names));
        }
        $components = [];
        foreach (DB::table('application_health_components')->where('check_id', $checkId)
            ->whereIn('source_key', array_map(static fn (string $name): string => hash('sha256', $name), $names))->get() as $row) {
            /** @var \stdClass&object{id: int, source_key: string, change_identity: string|null, run_id: int|null, snapshot: string|null} $row */
            /** @var array{status: string, latest_result?: string, unresolved_result?: ?string}|null $snapshot */
            $snapshot = $row->snapshot === null ? null : json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $components[$row->source_key] = ['id' => $row->id, 'change_identity' => $row->change_identity, 'run_id' => $row->run_id, 'snapshot' => $snapshot];
        }

        return $components;
    }

    public function detail(int $checkId): ApplicationHealthDetailData
    {
        $valid = DB::table('application_health_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['run_id', 'run_ended_at']);
        /** @var (\stdClass&object{run_id: int, run_ended_at: string})|null $valid */
        $componentsPage = $this->paging->paginate(DB::table('application_health_components')->where('check_id', $checkId)->where('active', true)
            ->whereNotNull('snapshot')->orderBy('id')->select(['snapshot', 'run_id', 'run_ended_at']), 'health_components');
        $advisoriesPage = $this->paging->paginate(DB::table('application_health_advisories')->where('check_id', $checkId)
            ->orderBy('package')->orderByDesc('reported_at')->orderBy('id'), 'health_advisories');
        /** @var list<\stdClass> $advisoryRows */
        $advisoryRows = $advisoriesPage->items();
        $advisories = array_map(static function (object $advisory): SecurityAdvisoryData {
            /** @var \stdClass&object{package: string, advisory_id: string, title: string, link: string, cve: string|null, severity: string|null, affected_versions: string, reported_at: string} $advisory */
            return new SecurityAdvisoryData($advisory->package, $advisory->advisory_id, $advisory->title, $advisory->link, $advisory->cve,
                $advisory->severity, $advisory->affected_versions, CarbonImmutable::parse($advisory->reported_at)->utc()->toIso8601String());
        }, $advisoryRows);
        $components = [];
        /** @var list<\stdClass> $componentRows */
        $componentRows = $componentsPage->items();
        foreach ($componentRows as $row) {
            /** @var \stdClass&object{snapshot: string, run_id: int, run_ended_at: string} $row */
            /** @var array{name: string, label: string, status: string, shortSummary: string, latest_result: string} $snapshot */
            $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $components[] = [
                'name' => mb_substr($snapshot['name'], 0, 160),
                'label' => mb_substr($snapshot['label'], 0, 160),
                'status' => mb_substr($snapshot['status'], 0, 160),
                'short_summary' => mb_substr($snapshot['shortSummary'], 0, 160),
                'latest_result' => $snapshot['latest_result'],
                'advisories' => $snapshot['name'] === SecurityAdvisoryData::Component ? $advisories : null,
            ];
            $valid ??= (object) ['run_id' => $row->run_id, 'run_ended_at' => $row->run_ended_at];
        }
        $runs = DB::table('check_runs')->where('check_id', $checkId)->orderByDesc('ended_at')->orderByDesc('id')->limit(3)->get();
        $attempts = [];
        foreach ($runs as $run) {
            /** @var \stdClass&object{id: int, ended_at: string, result: string, evidence: string, metrics: string|null} $run */
            /** @var array<string, mixed> $evidence */
            $evidence = json_decode($run->evidence, true, flags: JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $metrics */
            $metrics = $run->metrics === null ? [] : json_decode($run->metrics, true, flags: JSON_THROW_ON_ERROR);
            $attempts[] = [
                'run_id' => $run->id, 'ended_at' => CarbonImmutable::parse($run->ended_at)->utc()->toIso8601String(), 'result' => $run->result,
                'reason' => is_string($evidence['reason'] ?? null) ? mb_substr($evidence['reason'], 0, 160) : null,
                'http_status' => is_int($evidence['http_status'] ?? null) ? $evidence['http_status'] : null,
                'response_time_ms' => is_numeric($metrics['response_time_in_seconds'] ?? null) ? (float) $metrics['response_time_in_seconds'] * 1000 : null,
            ];
        }
        $lastAttemptAt = $attempts[0]['ended_at'] ?? null;

        return new ApplicationHealthDetailData($valid?->run_id, $valid === null ? null : CarbonImmutable::parse($valid->run_ended_at)->utc()->toIso8601String(),
            $valid !== null && $lastAttemptAt !== null && CarbonImmutable::parse($lastAttemptAt)->greaterThan(CarbonImmutable::parse($valid->run_ended_at)),
            $components, PaginationData::fromPaginator($componentsPage), $attempts, PaginationData::fromPaginator($advisoriesPage));
    }

    public function stage(CheckContext $context, string $dataset, array $rows): void
    {
        if ($dataset !== 'components' || count($rows) !== 1) {
            throw new \InvalidArgumentException('Application health requires one components snapshot.');
        }
        /** @var array{components: list<array{snapshot: array{id: int, name: string, label: string, status: string, notificationMessage: string, shortSummary: string, meta: array<int|string, mixed>, latest_result: string, unresolved_result: ?string}, change_identity: string}>} $validated */
        $validated = Validator::make($rows[0], [
            'components' => ['present', 'array', 'list', 'max:1000'],
            'components.*' => ['required', 'array:snapshot,change_identity'],
            'components.*.change_identity' => ['required', 'string', 'size:64'],
            'components.*.snapshot' => ['required', 'array:id,name,label,status,notificationMessage,shortSummary,meta,latest_result,unresolved_result'],
            'components.*.snapshot.id' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
            'components.*.snapshot.name' => ['present', 'string', 'max:255'],
            'components.*.snapshot.label' => ['present', 'string', 'max:255'],
            'components.*.snapshot.status' => ['present', 'string', 'max:255'],
            'components.*.snapshot.notificationMessage' => ['present', 'string', 'max:255'],
            'components.*.snapshot.shortSummary' => ['present', 'string', 'max:255'],
            'components.*.snapshot.meta' => ['present', 'array'],
            'components.*.snapshot.latest_result' => ['required', 'in:succeeded,warning,failed,pending'],
            'components.*.snapshot.unresolved_result' => ['present', 'nullable', 'in:warning,failed'],
        ])->validate();
        $ids = array_map(static fn (array $row): int => $row['snapshot']['id'], $validated['components']);
        if (DB::table('application_health_components')->where('check_id', $context->checkId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw new \InvalidArgumentException('Application health component belongs to another check.');
        }
        DB::transaction(function () use ($context, $validated): void {
            $row = DB::table('application_health_snapshots')->where('run_id', $context->runId)->where('claim_token', $context->claimToken)->lockForUpdate()->first();
            if ($row !== null && $row->run_ended_at !== null) {
                throw new \LogicException('Cannot stage an accepted application health snapshot.');
            }
            DB::table('application_health_snapshots')->updateOrInsert(['run_id' => $context->runId, 'claim_token' => $context->claimToken], [
                'check_id' => $context->checkId,
                'components' => json_encode($validated['components'], JSON_THROW_ON_ERROR),
                'created_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function finalize(RunReference $run, string $claimToken): void
    {
        DB::transaction(function () use ($run, $claimToken): void {
            $query = DB::table('application_health_snapshots')->where('run_id', $run->id)->where('claim_token', $claimToken);
            $row = $query->lockForUpdate()->first();
            // Endpoint failures have no component observation to publish.
            if ($row === null) {
                return;
            }
            /** @var \stdClass&object{check_id: int, components: string, run_ended_at: string|null} $row */
            if ($row->run_ended_at !== null) {
                if (! CarbonImmutable::parse($row->run_ended_at)->eq($run->endedAt)) {
                    throw new \LogicException('Application health attempt finalized at a different timestamp.');
                }

                return;
            }
            /** @var list<array{snapshot: array{id: int, name: string, meta: array<int|string, mixed>, latest_result: string}, change_identity: string}> $components */
            $components = json_decode($row->components, true, flags: JSON_THROW_ON_ERROR);
            $endedAt = $run->endedAt->format('Y-m-d H:i:s.uP');
            DB::table('application_health_components')->where('check_id', $row->check_id)->update(['active' => false]);
            foreach ($components as $component) {
                DB::table('application_health_components')->where('check_id', $row->check_id)->where('id', $component['snapshot']['id'])->update([
                    'snapshot' => json_encode($component['snapshot'], JSON_THROW_ON_ERROR),
                    'change_identity' => $component['change_identity'], 'active' => true,
                    'run_id' => $run->id, 'run_ended_at' => $endedAt, 'updated_at' => $endedAt,
                ]);
            }
            $this->replaceAdvisories($row->check_id, $components);
            $query->update(['run_ended_at' => $endedAt]);
        });
    }

    /**
     * A skipped advisories component verified nothing, so the previous advisories stay open.
     *
     * @param  list<array{snapshot: array{name: string, meta: array<int|string, mixed>, latest_result: string}}>  $components
     */
    private function replaceAdvisories(int $checkId, array $components): void
    {
        $component = array_find($components, static fn (array $component): bool => $component['snapshot']['name'] === SecurityAdvisoryData::Component)['snapshot'] ?? null;
        if ($component !== null && $component['latest_result'] === 'pending') {
            return;
        }
        DB::table('application_health_advisories')->where('check_id', $checkId)->delete();
        $rows = array_map(static fn (SecurityAdvisoryData $advisory): array => [
            'check_id' => $checkId, 'package' => $advisory->package, 'advisory_id' => $advisory->advisory_id,
            'title' => $advisory->title, 'link' => $advisory->link, 'cve' => $advisory->cve, 'severity' => $advisory->severity,
            'affected_versions' => $advisory->affected_versions, 'reported_at' => $advisory->reported_at,
        ], $component === null ? [] : SecurityAdvisoryData::fromSpatieMeta($component['meta']));
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('application_health_advisories')->insert($chunk);
        }
    }

    public function discard(int $runId, string $claimToken): void
    {
        DB::table('application_health_snapshots')->where('run_id', $runId)->where('claim_token', $claimToken)->whereNull('run_ended_at')->delete();
    }

    public function prunedTables(): array
    {
        return ['application_health_snapshots'];
    }

    public function prune(CarbonImmutable $before): void
    {
        DB::table('application_health_snapshots')->where('run_ended_at', '<', $before->format('Y-m-d H:i:s.uP'))->delete();
    }
}

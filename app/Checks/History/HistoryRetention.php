<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class HistoryRetention
{
    public function hourlyCandidates(CarbonImmutable $at, RetentionPolicy $policy, bool $covered = true): Builder
    {
        return DB::table('check_run_rollups_hourly as hourly')
            ->where('hourly.bucket_start', '<', $at->utc()->subDays($policy->hourlyDays))
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')->from('check_run_rollups_daily as daily')
                    ->whereColumn('daily.check_id', 'hourly.check_id')
                    ->whereRaw("daily.bucket_start = date_trunc('day', hourly.bucket_start, 'UTC')")
                    ->whereRaw("daily.computed_at >= daily.bucket_start + interval '1 day 10 minutes'");
            }, not: ! $covered);
    }

    /** @return array{deleted: int, uncovered: int} */
    public function pruneHourly(CarbonImmutable $at, RetentionPolicy $policy): array
    {
        $deleted = 0;
        if ($policy->hourlyDeletionEnabled) {
            do {
                $limit = min(10000, RetentionPolicy::PerTableCap - $deleted);
                $candidates = $this->hourlyCandidates($at, $policy)->select('hourly.check_id', 'hourly.bucket_start')
                    ->orderBy('hourly.bucket_start')->limit($limit);
                $batch = DB::affectingStatement('DELETE FROM check_run_rollups_hourly WHERE (check_id, bucket_start) IN ('.$candidates->toSql().')', $candidates->getBindings());
                $deleted += $batch;
            } while ($batch === $limit && $deleted < RetentionPolicy::PerTableCap);
        }

        return ['deleted' => $deleted, 'uncovered' => $this->hourlyCandidates($at, $policy, covered: false)->count()];
    }

    /** @return list<array{table: string, eligible: int, oldest: ?string, capped: int, cap: int, exemptions: string, deletion_enabled: bool}> */
    public function report(CarbonImmutable $at, RetentionPolicy $policy): array
    {
        $cutoff = $at->utc()->subDays($policy->historyDays);
        $incidents = DB::table('incidents as incident')->where('incident.resolved_at', '<', $cutoff)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('incidents as open')->whereNull('open.resolved_at')
                    ->whereColumn('open.check_id', 'incident.check_id')->whereColumn('open.alert_episode_started_at', 'incident.alert_episode_started_at');
            });
        $keptIncident = DB::table('incidents')->select('incidents.id')->whereNotIn('incidents.id', (clone $incidents)->select('incident.id'));
        $oldEvents = DB::table('events as event')->where('event.occurred_at', '<', $cutoff);
        $keptIncidentEvents = (clone $oldEvents)->whereIn('event.incident_id', $keptIncident);
        $pendingEvents = (clone $oldEvents)->whereExists(function (Builder $query): void {
            $query->selectRaw('1')->from('deliveries')->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Sending->value])
                ->where(function (Builder $query): void {
                    $query->whereColumn('deliveries.event_id', 'event.id')->orWhereColumn('deliveries.digest_event_id', 'event.id');
                });
        });
        // Retain digests with source deliveries so deleting a digest cannot cascade into a kept event's delivery.
        $digestEvents = (clone $oldEvents)->whereExists(function (Builder $query): void {
            $query->selectRaw('1')->from('deliveries')->whereColumn('deliveries.digest_event_id', 'event.id');
        });
        $events = (clone $oldEvents)->whereNotIn('event.id', (clone $keptIncidentEvents)->select('event.id'))
            ->whereNotIn('event.id', (clone $pendingEvents)->select('event.id'))
            ->whereNotIn('event.id', (clone $digestEvents)->select('event.id'));
        $deliveries = DB::table('deliveries')->whereIn('event_id', (clone $events)->select('event.id'));
        $exemptDeliveries = DB::table('deliveries')->whereIn('event_id', (clone $oldEvents)->select('event.id'))->count() - $deliveries->count();
        $uncovered = $this->hourlyCandidates($at, $policy, covered: false)->count();
        $open = DB::table('incidents')->whereNull('resolved_at')->count();
        $episode = DB::table('incidents')->where('resolved_at', '<', $cutoff)->count() - $incidents->count();

        return [
            $this->reportRow('check_run_rollups_hourly', $this->hourlyCandidates($at, $policy), 'hourly.bucket_start', 'daily coverage missing='.$uncovered, deletionEnabled: $policy->hourlyDeletionEnabled),
            $this->reportRow('check_run_rollups_daily', DB::table('check_run_rollups_daily')->where('bucket_start', '<', $at->utc()->subDays($policy->dailyDays)), 'bucket_start'),
            $this->reportRow('events', $events, 'event.occurred_at', 'kept incident='.$keptIncidentEvents->count().'; pending/sending='.$pendingEvents->count().'; digest sources retained='.$digestEvents->count()),
            $this->reportRow('deliveries', $deliveries->join('events', 'events.id', '=', 'deliveries.event_id'), 'events.occurred_at', 'retained with exempt events='.$exemptDeliveries),
            $this->reportRow('incidents', $incidents, 'incident.resolved_at', 'open='.$open.'; open episode='.$episode),
            $this->reportRow('activity_log', DB::table('activity_log')->where('created_at', '<', $cutoff), 'created_at'),
            $this->reportRow('failed_jobs', DB::table('failed_jobs')->where('failed_at', '<', $at->utc()->subDays($policy->failedJobDays)), 'failed_at'),
            $this->reportRow('job_batches', DB::table('job_batches')->whereNotNull('finished_at')->where('finished_at', '<', $at->subDays($policy->failedJobDays)->getTimestamp()), 'finished_at'),
        ];
    }

    /** @return array{table: string, eligible: int, oldest: ?string, capped: int, cap: int, exemptions: string, deletion_enabled: bool} */
    private function reportRow(string $table, Builder $query, string $timestamp, string $exemptions = '', bool $deletionEnabled = false): array
    {
        $count = (clone $query)->count();
        /** @var int|string|null $oldest */
        $oldest = (clone $query)->min($timestamp);
        if (is_int($oldest)) {
            $oldest = CarbonImmutable::createFromTimestampUTC($oldest)->toIso8601String();
        }

        return ['table' => $table, 'eligible' => $count, 'oldest' => $oldest,
            'capped' => min($count, RetentionPolicy::PerTableCap), 'cap' => RetentionPolicy::PerTableCap, 'exemptions' => $exemptions, 'deletion_enabled' => $deletionEnabled];
    }
}

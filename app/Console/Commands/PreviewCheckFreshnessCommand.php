<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckFreshness;
use Heptapod\Models\Check;
use Heptapod\Models\SystemIssue;
use Heptapod\SystemHealth\CheckFreshnessIssues;
use Heptapod\SystemHealth\EvaluateSystemIssues;
use Heptapod\SystemHealth\SystemIssueKind;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Before migration, rebuild its conservative episode. After migration, report the canonical stored episode. */
#[Description('Read-only preview of the freshness backfill and first issue evaluation (before or after migration)')]
#[Signature('heptapod:preview-check-freshness')]
final class PreviewCheckFreshnessCommand extends Command
{
    public function handle(CheckFreshness $freshness, CheckFreshnessIssues $issues, EvaluateSystemIssues $evaluator): int
    {
        DB::transaction(function () use ($freshness, $issues, $evaluator): void {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $now = CarbonImmutable::now('UTC');
            $suppressed = $evaluator->suppressesCheckFreshness($now);
            $open = SystemIssue::open()->where('kind', SystemIssueKind::CheckNotFresh->value)->getQuery()->pluck('scope')->all();
            $rows = [];
            $frontier = DB::table('checks')->max('id') ?? 0;
            $query = Check::query()->with('site')->where('id', '<=', $frontier);
            $query->getQuery()->select('checks.*');
            $hasStoredEpisode = Schema::hasColumn('checks', 'awaiting_result_since');
            if ($hasStoredEpisode) {
                $query->getQuery()->whereNotNull('awaiting_result_since');
            } else {
                $query->getQuery()->whereNotNull('claim_token')->selectRaw(<<<'SQL'
                    (SELECT min(a.requested_at) FROM check_run_attempts a
                        WHERE a.check_id = checks.id
                            AND a.requested_at > COALESCE(checks.latest_run_ended_at, '-infinity'::timestamptz)
                            AND (a.phase IN ('queued', 'running') OR (a.phase = 'skipped' AND a.note = 'lease_replaced'))) AS awaiting_result_since,
                    (SELECT count(*) FROM check_run_attempts a
                        WHERE a.check_id = checks.id
                            AND a.requested_at > COALESCE(checks.latest_run_ended_at, '-infinity'::timestamptz)
                            AND a.phase = 'skipped' AND a.note = 'lease_replaced') AS lost_attempts,
                    (SELECT a.started_at FROM check_run_attempts a
                        WHERE a.check_id = checks.id
                            AND a.requested_at > COALESCE(checks.latest_run_ended_at, '-infinity'::timestamptz)
                            AND a.phase = 'skipped' AND a.note = 'lease_replaced'
                        ORDER BY a.id DESC LIMIT 1) AS last_loss_started_at
                    SQL);
            }
            $this->line($hasStoredEpisode ? 'Episode source: stored checks columns' : 'Episode source: pre-migration attempt backfill');
            $query->chunkById(500, function (Collection $checks) use ($freshness, $issues, $now, $suppressed, $open, &$rows): void {
                foreach ($checks as $check) {
                    if ($freshness->state($check, $now)->state !== 'not_fresh') {
                        continue;
                    }
                    $reason = $suppressed ? 'instance_alarm' : ($issues->siteDown($check->site_id) ? 'uptime_down' : null);
                    $rows[] = [$check->id, ($check->site ?? throw new \LogicException('Freshness requires an existing site.'))->displayName(), $check->type->value,
                        $check->awaiting_result_since?->toIso8601String(), $freshness->threshold($check), $check->lost_attempts,
                        in_array((string) $check->id, $open, true) ? 'ALREADY OPEN' : ($reason === null ? 'WOULD OPEN' : 'SUPPRESSED: '.$reason)];
                }
            });
            $this->line('READ ONLY — backfill + first evaluation at '.$now->toIso8601String());
            $this->table(['Check', 'Site', 'Type', 'Awaiting since', 'Threshold (s)', 'Lost', 'Outcome'], $rows);
            $this->line(count($rows).' not-fresh checks; no writes, issues or notifications.');
        });

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CauseSummary;
use Heptapod\Contracts\BrokenLinkSettings;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Events\EventOutbox;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Incidents\IncidentTransitions;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Support\RedactionContext;
use Illuminate\Support\Facades\DB;

/** Runs under the settings transaction's check lock, before the resulting state is visible. */
final readonly class ReevaluateBrokenLinks
{
    public function __construct(private CauseSummary $summaries, private IncidentTransitions $incidents, private EventOutbox $outbox) {}

    /** @param list<string> $before
     * @param  list<string>  $after
     */
    public function __invoke(Check $check, BrokenLinkSettings $findings, array $before, array $after): void
    {
        NotificationLocks::sites([$check->site_id]);
        $site = $check->site()->getQuery()->firstOrFail();
        $check->setRelation('site', $site);
        $at = CarbonImmutable::now('UTC');
        $from = $check->latest_run_result;
        $this->outbox->record($check, $at, 'check.ignored_urls_changed', $from, $from, [], suppressionReason: 'ignored_urls_changed');
        LiveSignal::forSite($site, ['check' => $check->id]);
        if ($check->latest_run_id === null || $check->latest_run_ended_at === null) {
            return;
        }
        $reference = new RunReference($check->latest_run_id, $check->latest_run_ended_at);
        $run = CheckRun::forRun($reference)->where('check_id', $check->id)->first();
        if (! $run instanceof CheckRun || ! in_array($run->result, [RunResult::Succeeded, RunResult::Warning, RunResult::Failed], true) || $run->metrics === null) {
            return;
        }
        $counts = $findings->reEvaluateIgnoredUrls($check->id, $reference, $before, $after, RedactionContext::forSite($site, null)->secrets, $site->url);
        if ($counts === null) {
            return;
        }
        $incomplete = ($run->evidence['crawl_truncated'] ?? false) === true;
        $oldResult = $run->result;
        $result = $counts['internal'] > 0 ? RunResult::Failed : ($counts['external'] > 0 || $counts['rate_limited'] || ($run->evidence['unchecked_count'] ?? 0) > 0 ? RunResult::Warning : RunResult::Succeeded);
        $evidence = $run->evidence;
        // A settings change is not a newly observed failure.
        if (is_array($evidence['presentation_events'] ?? null)) {
            unset($evidence['presentation_events']['broken_links.new_broken_found']);
        }
        $run->result = $result;
        $run->metrics = [...$run->metrics, 'broken_internal' => $counts['internal'], 'broken_external' => $counts['external']];
        $run->evidence = [...$evidence, 'finding_ids' => array_slice($counts['ids'], 0, 10), 'truncated' => count($counts['ids']) > 10];
        if (! $incomplete) {
            $run->summary = 'broken_links.'.$result->value;
            $run->summary_params = [];
        }
        $summary = $this->summaries->project($check, $run);
        $run->save();
        if ($oldResult !== $result) {
            DB::update(<<<'SQL'
                UPDATE check_run_attempts SET history_result_repeated = attempt_history_result_repeated(check_id, check_run_id, finished_at, phase)
                WHERE check_id = ? AND phase IN ('done', 'failed') AND check_run_id = ? AND finished_at = ?::timestamptz
                SQL, [$check->id, $run->id, $run->ended_at->format('Y-m-d H:i:s.uP')]);
        }
        // A partial observation that never changed the accepted state cannot clear an unrelated incident.
        $to = $incomplete && $oldResult->value !== $from->value ? $from : CheckResult::from($result->value);
        $opening = $from !== CheckResult::Failed && $to === CheckResult::Failed;
        $check->update(['latest_run_result' => $to, 'summary' => $summary['key'], 'summary_params' => $summary['params'],
            ...($opening ? ['failing_since' => $at, 'consecutive_failures' => $check->failure_threshold] : []),
            ...($from === CheckResult::Failed && $to !== CheckResult::Failed ? ['failing_since' => null, 'consecutive_failures' => 0] : []),
        ]);
        if ($from !== $to) {
            $incident = $check->enabled && $site->paused_at === null ? $this->incidents->apply($check, $run, $from, settingsChangedAt: $at) : null;
            $restored = $opening || ($from === CheckResult::Succeeded && $to === CheckResult::Warning);
            $this->outbox->record($check, $at, 'check.result_changed', $from, $to, [
                'run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\TH:i:s.uP'),
                'summary' => $run->summary, 'summary_params' => $run->summary_params, 'reason' => 'ignored_urls_changed',
            ], $incident, $incident?->cause, suppressionReason: $restored ? null : 'ignored_urls_changed');
        }
    }
}

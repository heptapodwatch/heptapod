<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Checks\CauseSummary;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\FindingReclassificationPlan;
use Heptapod\Contracts\FindingReclassifier;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunResult;
use Heptapod\Events\EventOutbox;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade step (docker/prod/entrypoint.sh, after migrate, before the app is healthy and workers start):
 * relabels each check's latest legacy crawl snapshot under the current policy, silently.
 * A policy change is not a recovery: no result or incident events, only a timeline note, and alerts that only
 * the relabeled findings explained are never sent. Incidents are never resolved here.
 */
#[Signature('heptapod:reclassify-links {--policy= : Classification policy version to apply} {--dry-run : Preview reclassification in a read-only transaction}')]
final class ReclassifyLinksCommand extends Command
{
    public const string SuppressionReason = 'policy_reclassified';

    public function getDescription(): string
    {
        return __('reclassify_links.description');
    }

    public function handle(FindingReclassifier $reclassifier, EventOutbox $outbox, CauseSummary $summaries): int
    {
        if ($this->option('dry-run') !== true) {
            return $this->reclassify($reclassifier, $outbox, $summaries, false);
        }

        // Preflight: one read-only transaction, so it can run against the pre-upgrade schema without any write.
        return DB::transaction(function () use ($reclassifier, $outbox, $summaries): int {
            DB::statement('SET TRANSACTION READ ONLY');

            return $this->reclassify($reclassifier, $outbox, $summaries, true);
        });
    }

    private function reclassify(FindingReclassifier $reclassifier, EventOutbox $outbox, CauseSummary $summaries, bool $dryRun): int
    {
        if ((string) $this->option('policy') !== (string) $reclassifier->policyVersion()) {
            $this->error(__('reclassify_links.unknown_policy', ['version' => $reclassifier->policyVersion()]));

            return self::FAILURE;
        }
        $pending = $reclassifier->pendingChecks();
        if ($pending === []) {
            $this->info(__('reclassify_links.nothing_to_do'));

            return self::SUCCESS;
        }
        // Every blocker is collected before the first write, so a failed run changes nothing, a rerun continues,
        // and --dry-run is a complete go/no-go.
        $blockers = [];
        // A live lease may still be talking to a provider; an expired lease is an abandoned claim that gets retried, so it is cancellable.
        // TODO: S4 moves this to the app clock (C10–C13).
        $sending = DB::table('deliveries')->where('state', DeliveryState::Sending->value)->where('lease_until', '>', app(DatabaseClock::class)->now()->format('Y-m-d H:i:s.uP'))->count();
        if ($sending > 0) {
            $blockers[] = trans_choice('reclassify_links.deliveries_sending', $sending, ['count' => $sending]);
        }
        $plans = [];
        foreach ($pending as $checkId) {
            $plan = $reclassifier->plan($checkId);
            if ($plan instanceof FindingReclassificationPlan) {
                $plans[] = $plan;
            }
        }
        $rows = [];
        if ($blockers !== [] && ! $dryRun) {
            foreach ($blockers as $blocker) {
                $this->error($blocker);
            }

            return self::FAILURE;
        }
        foreach ($plans as $plan) {
            if ($dryRun) {
                $check = Check::query()->with('site')->findOrFail($plan->checkId);
                $rows[] = $this->row($check, $plan, $this->obsoleteAlerts($check, $plan, $reclassifier));

                continue;
            }
            $row = DB::transaction(function () use ($plan, $reclassifier, $outbox, $summaries): ?array {
                // NotificationLocks: the check and its site (both exclusive, as the recorder holds them), then the deliveries.
                NotificationLocks::checks([], exclusive: [$plan->checkId]);
                $check = Check::query()->find($plan->checkId);
                if (! $check instanceof Check) {
                    return null;
                }
                NotificationLocks::sites([$check->site_id], exclusive: true);
                $check->setRelation('site', Site::query()->findOrFail($check->site_id));
                // Re-plan under the lock; another process may have accepted a newer snapshot since the scan.
                $locked = $reclassifier->plan($check->id);
                if (! $locked instanceof FindingReclassificationPlan) {
                    return null;
                }
                // TODO: S4 moves this to the app clock (C10–C13).
                $at = app(DatabaseClock::class)->now();
                [$events, $deliveries] = $this->obsoleteAlerts($check, $locked, $reclassifier);
                foreach ($events as $event) {
                    if ($event->notification_routed_at === null) {
                        $event->update(['suppressed' => true, 'suppression_reason' => self::SuppressionReason, 'suppression_evaluated_at' => $at]);
                    }
                }
                // NotificationLocks: the deliveries in ascending id before their updates (archive and claim take them in that order).
                NotificationLocks::deliveries(array_map(static fn (Delivery $delivery): int => $delivery->id, $deliveries));
                foreach ($deliveries as $delivery) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => self::SuppressionReason, 'suppression_evaluated_at' => $at]);
                }
                $reclassifier->apply($locked);
                $resultBefore = $check->latest_run_result;
                $run = $locked->runResultBefore instanceof RunResult ? CheckRun::query()->findOrFail($locked->runId) : null;
                $run?->update(['result' => $locked->runResultAfter, 'metrics' => $locked->metrics, 'evidence' => [...$run->evidence ?? [], ...$locked->evidence],
                    'summary' => $locked->summaryAfter ?? $run->summary]);
                if ($run instanceof CheckRun && $locked->runResultAfter !== $locked->runResultBefore) {
                    // Result updates are not covered by the history triggers; recompute this run's and its successor's projection.
                    DB::update(<<<'SQL'
                        UPDATE check_run_attempts SET history_result_repeated = attempt_history_result_repeated(check_id, check_run_id, finished_at, phase)
                        WHERE check_id = ? AND phase IN ('done', 'failed') AND check_run_id IN (
                            ?, (SELECT id FROM check_runs WHERE check_id = ? AND (ended_at, id) > (?::timestamptz, ?) ORDER BY ended_at, id LIMIT 1))
                        SQL, [$check->id, $run->id, $check->id, $run->ended_at->format('Y-m-d H:i:s.uP'), $run->id]);
                }
                if ($run instanceof CheckRun && $locked->runResultAfter instanceof RunResult && $this->acceptsRun($check, $locked)) {
                    $summary = $summaries->project($check, $run);
                    $check->update(['latest_run_result' => CheckResult::from($locked->runResultAfter->value),
                        'summary' => $summary['key'] ?? throw new \LogicException('A run must have a summary.'), 'summary_params' => $summary['params']]);
                }
                // The accepted Warning may still rest on these relabeled findings (incomplete, deferred or pruned baseline):
                // the next complete evaluation must not report its disappearance as a recovery.
                if ($locked->relabeledFindingIds !== [] && $check->latest_run_result === CheckResult::Warning
                    && (! $locked->baselineComplete || ! $this->acceptsRun($check, $locked))) {
                    $check->update(['policy_reclassification_pending' => ['policy' => $locked->policyVersion, 'relabeled_ids' => $locked->relabeledFindingIds]]);
                }
                if ($locked->relabeledFindingIds !== []) {
                    $outbox->record($check, $at, 'check.findings_reclassified', $resultBefore, $check->latest_run_result,
                        ['run_id' => $locked->runId, 'policy_version' => $locked->policyVersion, 'count' => count($locked->relabeledFindingIds)],
                        suppressionReason: self::SuppressionReason);
                }

                return $this->row($check, $locked, [$events, $deliveries], $resultBefore);
            });
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        $this->table([__('Check'), __('Site'), __('Result'), __('reclassify_links.relabeled'), __('reclassify_links.still_counted'), __('reclassify_links.alerts_cancelled')], $rows);
        $this->info(trans_choice($dryRun ? 'reclassify_links.would_reclassify' : 'reclassify_links.reclassified', count($rows), ['count' => count($rows), 'version' => $reclassifier->policyVersion()]));
        foreach ($blockers as $blocker) {
            $this->error($blocker);
        }

        return $blockers === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Not yet delivered alerts of this check that only the relabeled findings explain. A warning is obsolete only when the
     * relabeled snapshot leaves nothing counted; a new-broken alert only when every finding it names was relabeled.
     *
     * @return array{list<Event>, list<Delivery>}
     */
    private function obsoleteAlerts(Check $check, FindingReclassificationPlan $plan, FindingReclassifier $reclassifier): array
    {
        if ($plan->relabeledFindingIds === []) {
            return [[], []];
        }
        $types = $plan->nothingRemains ? ['check.result_changed', 'broken_links.new_broken_found'] : ['broken_links.new_broken_found'];
        $query = Event::query()->where('check_id', $check->id)->where('suppressed', false);
        $query->getQuery()->whereIn('type', $types)->where(static function (QueryBuilder $open): void {
            $open->whereNull('notification_routed_at')->orWhereExists(static fn (QueryBuilder $delivery): QueryBuilder => $delivery->from('deliveries')
                ->whereColumn('deliveries.event_id', 'events.id')->where('deliveries.suppressed', false)->where(self::undelivered(...)));
        });
        $events = [];
        $deliveries = [];
        foreach ($query->lazyById(100) as $event) {
            if ($event->type === 'check.result_changed' && $event->to_result !== CheckResult::Warning) {
                continue;
            }
            $runId = $event->payload['run_id'] ?? null;
            $named = $event->payload['finding_ids'] ?? null;
            $ids = is_array($named) && array_is_list($named) && array_all($named, static fn (mixed $id): bool => is_string($id)) ? array_values(array_filter($named, is_string(...))) : null;
            // A warning names no findings: all counted findings of its run must be relabeled ones. A new-broken alert names its findings.
            $explained = is_int($runId) && ($event->type === 'check.result_changed'
                ? $reclassifier->explainedByRelabeling($check->id, $runId, null, $plan->relabeledFindingIds)
                : $ids !== null && ($event->payload['truncated'] ?? true) === false
                    && $reclassifier->explainedByRelabeling($check->id, $runId, $ids, $plan->relabeledFindingIds));
            if (! $explained) {
                continue;
            }
            $events[] = $event;
            $open = Delivery::query()->where('event_id', $event->id)->where('suppressed', false);
            $open->getQuery()->where(self::undelivered(...));
            array_push($deliveries, ...$open->get()->all());
        }

        return [$events, $deliveries];
    }

    /** Not sent and not in flight: pending (incl. digest and quiet-hours holds), failed, or an abandoned (expired) sending claim. */
    private static function undelivered(QueryBuilder $delivery): void
    {
        // TODO: S4 moves this to the app clock (C10–C13).
        $delivery->whereIn('deliveries.state', [DeliveryState::Pending->value, DeliveryState::Failed->value])->orWhere(static fn (QueryBuilder $abandoned): QueryBuilder => $abandoned
            ->where('deliveries.state', DeliveryState::Sending->value)->where('deliveries.lease_until', '<=', app(DatabaseClock::class)->now()->format('Y-m-d H:i:s.uP')));
    }

    /** The check accepted this run's result (not a deferred incomplete observation or a later run), so the policy change may update it. */
    private function acceptsRun(Check $check, FindingReclassificationPlan $plan): bool
    {
        return $plan->runResultBefore instanceof RunResult && $check->latest_run_id === $plan->runId && $check->latest_run_result === CheckResult::from($plan->runResultBefore->value);
    }

    /** @param array{list<Event>, list<Delivery>} $alerts
     * @return list<string|int>
     */
    private function row(Check $check, FindingReclassificationPlan $plan, array $alerts, ?CheckResult $before = null): array
    {
        $before ??= $check->latest_run_result;
        $accepted = $plan->runResultBefore instanceof RunResult && $plan->runResultAfter instanceof RunResult
            && ($this->acceptsRun($check, $plan) || ($check->latest_run_id === $plan->runId && $before === CheckResult::from($plan->runResultBefore->value)));
        $after = $accepted ? CheckResult::from($plan->runResultAfter->value) : $before;
        $note = $plan->runResultBefore instanceof RunResult ? $accepted ? null : 'reclassify_links.latest_run_not_crawl' : ('reclassify_links.run_pruned');
        $marker = $plan->relabeledFindingIds !== [] && $after === CheckResult::Warning && (! $plan->baselineComplete || ! $accepted);

        return [$check->id, ($check->site ?? throw new \LogicException('A check must have a site.'))->displayName().' · '.__('checks.'.$check->type->value.'.label'),
            $before->value.($after === $before ? '' : ' → '.$after->value).($note === null ? '' : ' ('.__($note).')').($marker ? ' · '.__('reclassify_links.marked') : ''),
            count($plan->relabeledFindingIds), count($plan->remainingFindingIds), count($alerts[0]).' / '.count($alerts[1])];
    }
}

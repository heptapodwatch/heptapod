<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Data\Ui\ShellHealthData;
use Heptapod\Enums\HealthStatus;
use Heptapod\Enums\SiteStatus;
use Heptapod\Events\Notifications\NotificationRecipients;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Check;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Heptapod\SystemHealth\SystemIssueKind;
use Illuminate\Support\Facades\DB;

final readonly class ShellHealthReader
{
    public function __construct(private SitesReader $sites, private NotificationRecipients $coverage, private CheckRegistry $registry) {}

    public function read(User $user): ShellHealthData
    {
        $heartbeatQuery = DispatcherHeartbeat::query();
        $heartbeatQuery->getQuery()->orderBy('last_seen_at', 'desc');
        $heartbeat = $heartbeatQuery->first()?->last_seen_at;
        $siteIds = $user->hasAnyRole(['Owner', 'Admin', 'Member']) ? $this->sites->visibleSiteIds($user) : [];
        $siteCount = DB::table('sites')->whereIntegerInRaw('sites.id', $siteIds)->selectRaw('count(*)');
        $incidentCount = DB::table('incidents')->whereNull('resolved_at')->whereIntegerInRaw('site_id', $siteIds)->whereRaw('NOT ('.UptimeDependencies::heldAfterRecoverySql('incidents').')')->selectRaw('count(*)');
        $checks = Check::query();
        $checks->getQuery()->whereIntegerInRaw('checks.site_id', $siteIds);
        $checks->where('enabled', true);
        $held = UptimeDependencies::heldAfterRecoverySql('held_incident');
        $staleBefore = CarbonImmutable::now('UTC')->subSeconds(config()->integer('heptapod.health.dispatcher_warn_seconds'));
        $stats = (clone $checks)->getQuery()->join('sites', 'sites.id', '=', 'checks.site_id')
            ->selectRaw('MAX(checks.latest_run_ended_at) AS latest')
            ->selectRaw("COUNT(*) FILTER (WHERE checks.latest_run_result = ? AND NOT EXISTS (
                SELECT 1 FROM incidents held_incident WHERE held_incident.check_id = checks.id AND ({$held}))) AS errors", [CheckResult::Errored->value])
            ->selectRaw('COUNT(*) FILTER (WHERE sites.paused_at IS NULL AND sites.marked_for_deletion_at IS NULL) AS active_checks')
            ->selectSub($siteCount, 'site_count')->selectSub($incidentCount, 'open_incident_count')
            ->selectRaw(<<<'SQL'
                COUNT(*) FILTER (WHERE sites.paused_at IS NULL AND sites.marked_for_deletion_at IS NULL
                    AND (checks.next_run_at < ? OR checks.lease_expires_at < ?)
                    AND (checks.latest_run_ended_at IS NULL OR checks.latest_run_ended_at < ?)) AS stale_count
                SQL, [$staleBefore, $staleBefore, $staleBefore])->first();
        $latest = $stats?->latest;
        if ($stats === null || ($latest !== null && ! is_string($latest))
            || (! is_int($stats->errors) && ! is_string($stats->errors))
            || (! is_int($stats->active_checks) && ! is_string($stats->active_checks))
            || (! is_int($stats->site_count) && ! is_string($stats->site_count))
            || (! is_int($stats->open_incident_count) && ! is_string($stats->open_incident_count))
            || (! is_int($stats->stale_count) && ! is_string($stats->stale_count))) {
            throw new \LogicException('Check health aggregates must contain counts and a timestamp.');
        }
        // The mark's Down count uses the projection behind the /sites state filter, so its link shows exactly these sites.
        $downSiteCount = $siteIds === [] ? 0 : DB::query()->fromSub($this->sites->projectedForSearch($user), 'visible_sites')
            ->where('state', SiteStatus::Down->value)->count();
        $errors = (int) $stats->errors;
        $stale = (int) $stats->stale_count > 0;
        $age = $heartbeat === null ? null : (int) $heartbeat->diffInSeconds(CarbonImmutable::now('UTC'));
        // Separate from the status: a system issue does not make current check results doubtful.
        // Only Owners and Admins see it, because only they can open System health, where it is explained.
        $issueQuery = SystemIssue::open();
        $issueQuery->getQuery()->orderBy('opened_at')->orderBy('id');
        $systemIssues = $user->hasAnyRole(['Owner', 'Admin']) ? $issueQuery->get() : null;
        $systemIssueStatus = $systemIssues === null || $systemIssues->isEmpty() ? null
            : ($systemIssues->contains(static fn (SystemIssue $issue): bool => $issue->severity() === HealthStatus::Down) ? HealthStatus::Down : HealthStatus::Warn);
        $status = match (true) {
            $age === null || $age > config()->integer('heptapod.health.dispatcher_warn_seconds') => HealthStatus::Down,
            $age > config()->integer('heptapod.health.dispatcher_ok_seconds') || $stale => HealthStatus::Warn,
            default => HealthStatus::Ok,
        };

        $healthAction = SystemIssueKind::SchedulerStale->navigationAction($user);
        $causes = [];
        if ($status !== HealthStatus::Ok && ($age === null || $age > config()->integer('heptapod.health.dispatcher_ok_seconds'))) {
            $causes[] = ['severity' => $status === HealthStatus::Down ? 0 : 1, 'title_key' => 'The check dispatcher has not reported recently', ...$healthAction];
        }
        foreach ($systemIssues ?? [] as $issue) {
            $causes[] = ['severity' => $issue->severity() === HealthStatus::Down ? 0 : 1, 'title_key' => $issue->kind->titleKey($issue->details),
                ...$issue->kind->navigationAction($user)];
        }
        foreach ($this->registry->unavailableKeys() as $key) {
            if ((clone $checks)->where('type', $key)->getQuery()->exists()) {
                $causes[] = ['severity' => 1, 'title_key' => $this->registry->availability($key)->reason ?? throw new \LogicException('An unavailable check needs a reason.'),
                    ...$healthAction];
            }
        }
        if ($stale) {
            $causes[] = ['severity' => 1, 'title_key' => 'Scheduled checks have no recent result', ...$healthAction];
        }
        usort($causes, static fn (array $a, array $b): int => $a['severity'] <=> $b['severity']);
        $causes = array_map(static fn (array $cause): array => ['title_key' => $cause['title_key'], 'action_key' => $cause['action_key'], 'href' => $cause['href']], array_slice($causes, 0, 3));

        return new ShellHealthData($status, $heartbeat?->toIso8601String(), $latest === null ? null : CarbonImmutable::parse($latest)->toIso8601String(), $errors, (int) $stats->active_checks, (int) $stats->site_count, (int) $stats->open_incident_count, $downSiteCount, $this->coverage->coversAny($siteIds), $systemIssueStatus, $systemIssues?->count() ?? 0, $causes);
    }
}

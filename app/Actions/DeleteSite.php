<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\SecretRedactor;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final readonly class DeleteSite
{
    public function __construct(private ResolveInactiveCheckIncidents $resolve) {}

    public function __invoke(Site $site): void
    {
        NotificationLocks::transaction(function () use ($site): void {
            // NotificationLocks: the site's maintenance recurrences (exclusive: the cascade deletes them, and generation holds
            // one while it inserts a window that takes this site's key), the checks (deleted by the cascade), the site, then
            // every incident the cascade deletes, open and closed (a profile deletion holds a closed incident its user noted
            // while it takes that user's actor deliveries), and in one ascending pass every delivery this deletion writes: the
            // queued alerts it suppresses and the history it redacts. The site's own maintenance windows are left to the
            // cascade at the end (the residual risk named in NotificationLocks).
            NotificationLocks::maintenanceRecurrences(DB::table('recurring_maintenance_periods')->where('site_id', $site->id)->pluck('id'), exclusive: true);
            /** @var list<int> $checkIds */
            $checkIds = DB::table('checks')->where('site_id', $site->id)->orderBy('id')->pluck('id')->all();
            NotificationLocks::checks([], exclusive: $checkIds);
            NotificationLocks::sites([$site->id], exclusive: true);
            $lockedSite = Site::query()->findOrFail($site->id);
            $lockedChecks = Check::query()->whereKey($checkIds)->get()->sortBy('id')->values();
            NotificationLocks::incidents(DB::table('incidents')->where('site_id', $site->id)->orWhereIn('check_id', $checkIds)->pluck('id'));
            ResolveInactiveCheckIncidents::lockWork($checkIds, DB::table('deliveries')->select('id')
                ->whereIn('event_id', DB::table('events')->select('id')->where('site_id', $site->id)));
            $ids = [];
            foreach ($lockedChecks as $check) {
                $ids = [...$ids, ...($this->resolve)($check, deletingSite: true)];
            }
            $eventIds = DB::table('events')->where('site_id', $lockedSite->id)->select('id');
            $siteUrls = DB::table('events')->where('site_id', $lockedSite->id)->select('site_url')
                ->union(DB::table('deliveries')->whereIn('event_id', $eventIds)->select('site_url'))->pluck('site_url');
            foreach ($siteUrls as $siteUrl) {
                if (! is_string($siteUrl)) {
                    throw new \LogicException('A site history URL must be text.');
                }
                $redactedUrl = SecretRedactor::redact($siteUrl, [], [$siteUrl]);
                if ($redactedUrl !== $siteUrl) {
                    DB::statement('WITH redacted_events AS (UPDATE events SET site_url = ? WHERE site_id = ? AND site_url = ? RETURNING id)
                        UPDATE deliveries SET site_url = ? WHERE site_url = ? AND event_id IN (SELECT id FROM events WHERE site_id = ?)',
                        [$redactedUrl, $lockedSite->id, $siteUrl, $redactedUrl, $siteUrl, $lockedSite->id]);
                }
            }
            // Older addon events relied on presentation-time redaction. Scrub them before their secrets disappear.
            $events = Event::query()->with('check')->where('site_id', $lockedSite->id);
            foreach ($events->lazyById(100) as $event) {
                $event->redactPayload($lockedSite);
                if ($event->isDirty('payload')) {
                    $event->save();
                }
            }
            $lockedSite->incidentResolutionAudit = ['origin' => ResolveInactiveCheckIncidents::auditOrigin(), 'reason' => IncidentResolutionReason::SiteDeleted->value,
                'resolved_incident_ids' => $ids, 'resolved_incident_count' => count($ids)];
            $lockedSite->delete();
        });
    }
}

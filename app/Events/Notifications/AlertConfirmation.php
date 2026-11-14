<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;

/**
 * Alert sensitivity for checks other than uptime: a Failed or Check error (an incident opening or a cause change) alerts
 * only when the next run shows the same problem. Incidents and check results are recorded as usual; only the alert waits,
 * and one the next run does not confirm is never sent.
 */
final class AlertConfirmation
{
    public const string SuppressionReason = 'recovered_before_confirmation';

    public const string Pending = 'pending';

    public const string Confirmed = 'confirmed';

    public const string Refuted = 'refuted';

    /** Hourly and daily checks re-run this soon after the first bad result instead of waiting a full interval. */
    public const int RerunAfterSeconds = 600;

    public static function rerunAfterSeconds(Check $check): int
    {
        return $check->interval_seconds >= 3600 ? self::RerunAfterSeconds : $check->interval_seconds;
    }

    public static function required(Check $check, Site $site): bool
    {
        return $check->type !== CheckKey::Uptime && EffectiveAlertSensitivity::for($site, app(InstanceSettings::class))->confirmsOtherChecks;
    }

    public static function appliesTo(Event $event): bool
    {
        return in_array($event->type, ['incident.opened', 'incident.cause_changed'], true) && $event->cause instanceof IncidentCause
            && $event->check instanceof Check && $event->site instanceof Site && self::required($event->check, $event->site);
    }

    /** Pending until the next run after the alerting observation; Confirmed when that run shows the same problem, else Refuted. */
    public static function state(Event $event): string
    {
        $at = self::observedAt($event);
        if (! $at instanceof CarbonImmutable) {
            return self::Pending;
        }
        $next = DB::table('check_runs')->where('check_id', $event->check_id)->where('ended_at', '>', $at->format('Y-m-d H:i:s.uP'))
            ->orderBy('ended_at')->orderBy('id')->value('result');
        $expected = $event->cause === IncidentCause::Errored ? RunResult::Errored : RunResult::Failed;

        return match ($next) {
            null => self::Pending,
            $expected->value => self::Confirmed,
            default => self::Refuted,
        };
    }

    /** When the confirming run is expected; null once the alert is no longer waiting for it. */
    public static function notBefore(Event $event): ?CarbonImmutable
    {
        if (! self::appliesTo($event) || self::state($event) !== self::Pending) {
            return null;
        }

        return (self::observedAt($event) ?? CarbonImmutable::now('UTC'))
            ->addSeconds(self::rerunAfterSeconds($event->check ?? throw new \LogicException('A confirmation needs its check.')));
    }

    /** Whether the check's next run decides a pending alert: such a run must observe the site again, never reuse cached evidence. */
    public static function awaitsConfirmingRun(Check $check, Site $site): bool
    {
        if (! self::required($check, $site)) {
            return false;
        }
        $query = Event::query()->where('check_id', $check->id);
        $query->getQuery()->whereIn('type', ['incident.opened', 'incident.cause_changed'])->where('suppressed', false)
            ->whereIn('incident_id', DB::table('incidents')->select('id')->where('check_id', $check->id)->whereNull('resolved_at'))
            ->orderByDesc('occurred_at')->orderByDesc('id');
        $latest = $query->first();

        return $latest instanceof Event && self::state($latest->setRelation('check', $check)->setRelation('site', $site)) === self::Pending;
    }

    /**
     * The observation that raised the alert. Catch-up routing rewrites an opening's occurrence time to the window end,
     * so an opening anchors on its incident; an explanation exemplar has none.
     */
    private static function observedAt(Event $event): ?CarbonImmutable
    {
        if (! $event->exists) {
            return null;
        }

        return $event->type === 'incident.opened' ? ($event->incident->opened_at ?? $event->occurred_at) : $event->occurred_at;
    }
}

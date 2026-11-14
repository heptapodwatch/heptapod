<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;

/**
 * Alert sensitivity for checks other than uptime: a Failed or Check error alerts only once the next run shows a problem
 * too. The incident opens and closes as usual; only its opening alert waits, and one that recovers first is never sent.
 */
final class AlertConfirmation
{
    public const string SuppressionReason = 'recovered_before_confirmation';

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
        return $event->type === 'incident.opened' && $event->check instanceof Check && $event->site instanceof Site
            && self::required($event->check, $event->site);
    }

    /** When the confirming run is expected; an explanation exemplar has no occurrence yet and starts now. */
    public static function notBefore(Event $event): ?CarbonImmutable
    {
        if (! self::appliesTo($event)) {
            return null;
        }
        $openedAt = $event->exists ? $event->occurred_at : CarbonImmutable::now('UTC');

        return $openedAt->addSeconds(self::rerunAfterSeconds($event->check ?? throw new \LogicException('A confirmation needs its check.')));
    }

    /** A Failed or Check error run after the one that opened the incident, while it was still open. */
    public static function confirmed(Incident $incident): bool
    {
        $query = DB::table('check_runs')->where('check_id', $incident->check_id)
            ->where('ended_at', '>', $incident->opened_at->format('Y-m-d H:i:s.uP'))
            ->whereIn('result', [RunResult::Failed->value, RunResult::Errored->value]);
        if ($incident->resolved_at !== null) {
            $query->where('ended_at', '<', $incident->resolved_at->format('Y-m-d H:i:s.uP'));
        }

        return $query->exists();
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Http\Resources\OhDear;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckFreshness;
use Heptapod\Checks\CheckSettingsProjection;
use Heptapod\Checks\FreshnessSnapshot;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Checks\PerformanceAvailability;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\EngineMessage;
use Heptapod\Events\Notifications\EffectiveAlertSensitivity;
use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Check */
final class CheckResource extends JsonResource
{
    #[\Override]
    public static $wrap;

    /**
     * @param  array<int, float|null>|null  $responseTimes  Site IDs to recent response times.
     * @param  FreshnessSnapshot|null  $freshness  Page-local inputs from list endpoints; single checks query directly.
     */
    public function __construct(mixed $resource, private readonly ?array $responseTimes = null, private readonly ?FreshnessSnapshot $freshness = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Check $check */
        $check = $this->resource;
        $snooze = $check->snoozes->first(fn (CheckSnooze $s): bool => $s->component_type === null && $s->unsnoozed_at === null && $s->starts_at <= now() && $s->ends_at > now());
        $settings = self::settings($check);
        if ($check->type === CheckKey::Uptime && array_key_exists('location', $settings)) {
            $settings['uptime_check_location'] = $settings['location'];
            unset($settings['location']);
        }
        $unavailable = app(CheckRegistry::class)->availability($check->type)->reason;
        $freshness = app(CheckFreshness::class);
        $now = $this->freshness->at ?? CarbonImmutable::now('UTC');
        $suppression = app(NoResultFreshness::class)->suppression($check, $now, $this->freshness);
        $performanceUnavailable = app(PerformanceAvailability::class)->forCheck($check, $suppression, $this->freshness?->uptime);
        $data = ['freshness_suppressed_by' => $suppression?->reason->value, 'freshness_suppressed_since' => $suppression?->sinceAt()?->toIso8601String(), 'freshness_latest_error_at' => $suppression?->latestErrorAt()?->toIso8601String(), 'display_result' => $suppression === null ? $check->latest_run_result->value : 'no_current_data', 'performance_unavailable' => $performanceUnavailable === null ? null : ['key' => $performanceUnavailable->key, 'params' => (object) $performanceUnavailable->params], 'freshness' => $freshness->state($check, $now)->toArray(),
            'last_attempt_loss' => $freshness->lastLoss($check), 'unavailable_reason' => $unavailable === null ? null : __($unavailable), 'id' => $check->id, 'type' => $check->type->value, 'label' => __(in_array($check->type, app(CheckRegistry::class)->keys(), true) ? $check->type->value.'.label' : 'checks.'.$check->type->value.'.label'), 'enabled' => $check->enabled,
            'latest_run_ended_at' => $check->latest_run_ended_at?->utc()->format('Y-m-d H:i:s'), 'latest_run_result' => $check->latest_run_result->value,
            'summary' => $check->summary === null ? null : __(...array_values(EngineMessage::normalize($check->summary, $check->summary_params ?? []))), 'settings' => (object) [$check->type->value.'_check_settings' => (object) $settings],
            'active_snooze' => $snooze === null ? null : ['id' => $snooze->id, 'starts_at' => $snooze->starts_at->utc()->format('Y-m-d H:i:s'), 'ends_at' => $snooze->ends_at->utc()->format('Y-m-d H:i:s'), 'snoozed_by' => $snooze->snoozed_by, 'unsnoozed_by' => $snooze->unsnoozed_by, 'source' => $snooze->source, 'created_at' => $snooze->created_at?->utc()->format('Y-m-d H:i:s')]];
        if ($check->type === CheckKey::Performance) {
            $times = $this->responseTimes ?? MonitorResource::responseTimes(collect([($check->site ?? throw new \LogicException('Check has no site'))->loadMissing('checks')]));
            $data['average_response_time_in_ms'] = $times[$check->site_id] ?? null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function settings(Check $check): array
    {
        $settings = CheckSettingsProjection::masked($check);
        if ($check->type !== CheckKey::Cron) {
            [$multiplier, $unit] = match (true) {
                $check->interval_seconds % 86400 === 0 => [86400, 'day'],
                $check->interval_seconds > 3600 && $check->interval_seconds % 3600 === 0 => [3600, 'hour'],
                default => [60, 'minute'],
            };
            $settings += ['failed_notification_threshold' => $check->type === CheckKey::Uptime
                ? EffectiveAlertSensitivity::for($check->site ?? throw new \LogicException('An uptime check needs its site.'), app(InstanceSettings::class))->alertAfterMinutes
                : $check->failure_threshold, 'frequency_value' => intdiv($check->interval_seconds, $multiplier), 'frequency_unit' => $unit];
        }

        /** @var array<string, mixed> $settings */
        return $settings;
    }
}

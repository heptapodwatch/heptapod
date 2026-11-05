<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Maintenance\LocalMaintenanceTime;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Maintenance\RecurringMaintenanceAttributes;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class CreateMaintenanceWindow
{
    public function __construct(private LocalMaintenanceTime $localTime,
        private RecurringMaintenanceAttributes $recurring, private GenerateMaintenancePeriods $generate, private MaintenanceTargets $targets) {}

    /** @param array<string, mixed> $input */
    public function __invoke(User $user, array $input): MaintenancePeriod|RecurringMaintenancePeriod
    {
        abort_unless($user->hasAnyRole(['Owner', 'Admin']), 403);
        $data = Validator::make($input, [
            'target_type' => ['required', Rule::in(['site', 'client', 'tag'])], 'target_id' => 'required|integer|min:1',
            'name' => 'nullable|string|max:255', 'reason' => 'nullable|string|max:255',
            'mute_alerts' => 'sometimes|boolean', 'catch_up_at_end' => 'sometimes|boolean',
            'timezone' => 'required|timezone:all', 'starts_local' => 'required|date_format:Y-m-d H:i',
            'ends_local' => 'required|date_format:Y-m-d H:i',
            'recurrence_type' => ['required', Rule::in(['once', 'daily', 'weekly', 'monthly'])],
            'interval' => 'sometimes|integer|between:1,52', 'days_of_week' => 'sometimes|array|min:1',
            'days_of_week.*' => 'integer|between:0,6|distinct', 'day_of_month' => 'sometimes|integer|between:1,31',
            'nth_weekday' => 'sometimes|integer|between:1,5', 'day_of_week' => 'sometimes|integer|between:0,6',
        ])->validate();
        /** @var array{target_type: 'site'|'client'|'tag', target_id: int, starts_local: string, ends_local: string, timezone: string,
         * recurrence_type: string, name?: string|null, reason?: string|null, mute_alerts?: bool, catch_up_at_end?: bool,
         * interval?: int, days_of_week?: list<int>, day_of_month?: int, nth_weekday?: int, day_of_week?: int} $data
         */
        $type = $data['target_type'];
        $id = $data['target_id'];
        $table = match ($type) {
            'site' => 'sites', 'client' => 'clients', 'tag' => 'tags'
        };
        Validator::make(['target_id' => $id], ['target_id' => Rule::exists($table, 'id')])->validate();
        $start = $this->localTime->resolve(substr($data['starts_local'], 0, 10), substr($data['starts_local'], 11), $data['timezone']);
        $end = $this->localTime->resolve(substr($data['ends_local'], 0, 10), substr($data['ends_local'], 11), $data['timezone']);
        Validator::make(['ends_at' => $end, 'starts_at' => $start], ['ends_at' => 'after:starts_at'])->validate();
        $siteId = $type === 'site' ? $id : null;
        $common = ['site_id' => $siteId, 'target_type' => $type, 'target_id' => $id,
            'name' => $data['name'] ?? null, 'reason' => $data['reason'] ?? null,
            'mute_alerts' => $data['mute_alerts'] ?? true, 'catch_up_at_end' => $data['catch_up_at_end'] ?? true,
            'timezone' => $data['timezone']];
        if ($data['recurrence_type'] === 'once') {
            return NotificationLocks::transaction(function () use ($common, $start, $end, $type, $id): MaintenancePeriod {
                $window = MaintenancePeriod::query()->create([...$common, 'starts_at' => $start, 'ends_at' => $end]);
                LiveSignal::forClients($this->targets->clientIds($type, $id));

                return $window;
            });
        }
        $localStart = CarbonImmutable::parse($data['starts_local'], 'UTC');
        $localEnd = CarbonImmutable::parse($data['ends_local'], 'UTC');
        $duration = (int) $localStart->diffInSeconds($localEnd);
        if ($duration < 1 || $duration > 86400) {
            throw ValidationException::withMessages(['ends_local' => __('Recurring windows must last no more than 24 hours.')]);
        }
        $endTime = $localEnd->format('H:i');
        $recurrence = $this->recurring->validate([
            ...$common, 'recurrence_type' => $data['recurrence_type'], 'interval' => $data['interval'] ?? 1,
            'days_of_week' => $data['days_of_week'] ?? null,
            'day_of_month' => $data['day_of_month'] ?? null,
            'nth_weekday' => $data['nth_weekday'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'start_time' => $localStart->format('H:i'), 'end_time' => $endTime,
            'anchor_date' => $localStart->format('Y-m-d'),
        ]);

        return NotificationLocks::transaction(function () use ($common, $recurrence): RecurringMaintenancePeriod {
            $period = RecurringMaintenancePeriod::query()->create([...$common, ...$recurrence]);
            ($this->generate)($period);

            return $period;
        });
    }
}

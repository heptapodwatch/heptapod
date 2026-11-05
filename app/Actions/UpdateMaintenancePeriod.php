<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\MaintenancePeriod;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class UpdateMaintenancePeriod
{
    public function __construct(private MaintenanceTargets $targets) {}

    /** @param array<string, mixed> $changes */
    public function __invoke(MaintenancePeriod $period, ?string $name, CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $changes = []): MaintenancePeriod
    {
        return NotificationLocks::transaction(function () use ($period, $name, $startsAt, $endsAt, $changes): MaintenancePeriod {
            // NotificationLocks: the window (exclusive), then (wakeWindow, on an ends_at or mute change) the deliveries it blocks.
            NotificationLocks::maintenanceWindows([$period->id], exclusive: true);
            $period = MaintenancePeriod::query()->findOrFail($period->id);
            $startsAt = $period->starts_at <= CarbonImmutable::now() ? $period->starts_at : $startsAt;
            Validator::make(['name' => $name, 'starts_at' => $startsAt, 'ends_at' => $endsAt], ['name' => 'nullable|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at'])->validate();
            /** @var array<string, mixed> $validated */
            $validated = Validator::make($changes, ['reason' => 'nullable|string|max:255', 'mute_alerts' => 'sometimes|boolean',
                'catch_up_at_end' => 'sometimes|boolean', 'timezone' => 'sometimes|timezone:all',
                'target_type' => 'sometimes|in:site,client,tag', 'target_id' => 'sometimes|integer|min:1'])->validate();
            if ($period->starts_at <= CarbonImmutable::now() && isset($validated['target_type'], $validated['target_id'])
                && ($validated['target_type'] !== $period->target_type || $validated['target_id'] !== ($period->target_id ?? $period->site_id))) {
                throw ValidationException::withMessages(['target_id' => 'An active window cannot change target.']);
            }
            $type = $validated['target_type'] ?? $period->target_type;
            $id = $validated['target_id'] ?? $period->target_id ?? $period->site_id;
            $affectedClientIds = $this->targets->clientIdsForWindow($period);
            $period->update(['name' => $name, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
                ...$validated, 'target_type' => $type, 'target_id' => $id, 'site_id' => $type === 'site' ? $id : null]);
            LiveSignal::forClients([...$affectedClientIds, ...$this->targets->clientIdsForWindow($period)]);

            return $period;
        });
    }
}

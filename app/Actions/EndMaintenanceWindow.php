<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\User;

final readonly class EndMaintenanceWindow
{
    public function __construct(private MaintenanceTargets $targets) {}

    public function __invoke(User $user, MaintenancePeriod $window): MaintenancePeriod
    {
        abort_unless($user->hasAnyRole(['Owner', 'Admin']), 403);

        return NotificationLocks::transaction(function () use ($window): MaintenancePeriod {
            // NotificationLocks: the window (exclusive), then (wakeWindow, on the ends_at update) the deliveries it blocks.
            NotificationLocks::maintenanceWindows([$window->id], exclusive: true);
            $window = MaintenancePeriod::query()->findOrFail($window->id);
            $now = CarbonImmutable::now('UTC');
            abort_unless($window->starts_at <= $now && $window->ends_at > $now, 409);
            $window->update(['ends_at' => $now]);
            LiveSignal::forClients($this->targets->clientIdsForWindow($window));

            return $window;
        });
    }
}

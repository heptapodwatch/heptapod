<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class SnoozeCheck
{
    public function __construct(private UnsnoozeCheck $unsnooze, private CheckRegistry $registry) {}

    /** An absolute end makes retries converge on the same snooze instead of extending it. */
    public function __invoke(Check $check, User $actor, CarbonImmutable $until): void
    {
        if (! in_array($check->type, $this->registry->keys(), true)) {
            throw ValidationException::withMessages(['type' => __('api.unsupported')]);
        }
        if ($until <= now() || $until > now()->addMinutes(525600)) {
            throw ValidationException::withMessages(['snooze_until' => __('api.invalid_snooze_until')]);
        }
        NotificationLocks::transaction(function () use ($check, $actor, $until): void {
            // NotificationLocks: the actor's key (the snooze names it), the check, then (UnsnoozeCheck) the woken deliveries.
            NotificationLocks::users([$actor->id]);
            NotificationLocks::checks([], exclusive: [$check->id]);
            Check::query()->findOrFail($check->id);
            ($this->unsnooze)($check, $actor);
            $check->snoozes()->create(['starts_at' => now(), 'ends_at' => $until, 'snoozed_by' => $actor->name, 'snoozed_by_user_id' => $actor->id, 'source' => 'api']);
        });
    }
}

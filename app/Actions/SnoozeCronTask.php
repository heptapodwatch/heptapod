<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class SnoozeCronTask
{
    public function __construct(private UnsnoozeCronTask $unsnooze) {}

    public function __invoke(Check $check, int $taskId, User $actor, int $minutes): void
    {
        if ($minutes < 1 || $minutes > 525600) {
            throw ValidationException::withMessages(['minutes' => __('api.invalid_minutes')]);
        }
        NotificationLocks::transaction(function () use ($check, $taskId, $actor, $minutes): void {
            // NotificationLocks: the actor's key (the snooze names it), then the check.
            NotificationLocks::users([$actor->id]);
            NotificationLocks::checks([], exclusive: [$check->id]);
            Check::query()->findOrFail($check->id);
            ($this->unsnooze)($check, $taskId, $actor);
            $check->snoozes()->create(['component_type' => SuppressionComponent::Cron, 'component_id' => $taskId,
                'starts_at' => now(), 'ends_at' => now()->addMinutes($minutes), 'snoozed_by' => $actor->name,
                'snoozed_by_user_id' => $actor->id, 'source' => 'api']);
        });
    }
}

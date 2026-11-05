<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CronTaskStore;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\User;

final readonly class UnsnoozeCronTask
{
    public function __construct(private CronTaskStore $store) {}

    public function __invoke(Check $check, int $taskId, User $actor): void
    {
        NotificationLocks::transaction(function () use ($check, $taskId, $actor): void {
            // NotificationLocks: the actor's key (the snooze names it), then the check.
            NotificationLocks::users([$actor->id]);
            NotificationLocks::checks([], exclusive: [$check->id]);
            Check::query()->findOrFail($check->id);
            abort_unless($this->store->find($taskId)->checkId === $check->id, 404);
            $check->snoozes()->getQuery()->getQuery()->where('component_type', SuppressionComponent::Cron->value)->where('component_id', $taskId)
                ->whereNull('unsnoozed_at')->where('starts_at', '<=', now()->format('Y-m-d H:i:s.uP'))->where('ends_at', '>', now()->format('Y-m-d H:i:s.uP'))
                ->update(['unsnoozed_at' => now()->format('Y-m-d H:i:s.uP'), 'unsnoozed_by' => $actor->name, 'unsnoozed_by_user_id' => $actor->id]);
        });
    }
}

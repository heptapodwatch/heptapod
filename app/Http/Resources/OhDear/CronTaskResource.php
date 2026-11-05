<?php

declare(strict_types=1);

namespace Heptapod\Http\Resources\OhDear;

use Heptapod\Contracts\CronPingUrl;
use Heptapod\Contracts\CronTaskData;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CronTaskData */
final class CronTaskResource extends JsonResource
{
    #[\Override]
    public static $wrap;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CronTaskData $task */
        $task = $this->resource;
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $canViewPingUrl = $actor->hasAnyRole(['Owner', 'Admin']);
        $query = CheckSnooze::query()->where('check_id', $task->checkId);
        $query->getQuery()->where('component_type', SuppressionComponent::Cron->value)->where('component_id', $task->id)
            ->whereNull('unsnoozed_at')->where('starts_at', '<=', now()->format('Y-m-d H:i:s.uP'))->where('ends_at', '>', now()->format('Y-m-d H:i:s.uP'));
        $snooze = $query->first();

        return [
            'id' => $task->id, 'uuid' => $canViewPingUrl ? $task->uuid : '********', ...$task->definition,
            'description' => $task->definition['description'] ?? '',
            'ping_url' => app(CronPingUrl::class)->for($canViewPingUrl ? $task->uuid : '00000000-0000-0000-0000-000000000000'),
            'created_at' => $task->createdAt->utc()->format('Y-m-d H:i:s'),
            'latest_result' => $task->latestResult,
            ...($task->latestPingAt === null ? [] : ['latest_ping_at' => $task->latestPingAt->utc()->format('Y-m-d H:i:s'), 'human_readable_latest_ping_at' => $task->latestPingAt->diffForHumans()]),
            'active_snooze' => $snooze === null ? null : [
                'id' => $snooze->id, 'starts_at' => $snooze->starts_at->utc()->format('Y-m-d H:i:s'),
                'ends_at' => $snooze->ends_at->utc()->format('Y-m-d H:i:s'), 'snoozed_by' => $snooze->snoozed_by,
                'unsnoozed_by' => $snooze->unsnoozed_by, 'source' => $snooze->source,
                'created_at' => $snooze->created_at?->utc()->format('Y-m-d H:i:s'),
            ],
        ];
    }
}

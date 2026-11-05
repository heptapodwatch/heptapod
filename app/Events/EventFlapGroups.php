<?php

declare(strict_types=1);

namespace Heptapod\Events;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Group identity is the first event ID; its nullable column stays null on the first member.
 *
 * @phpstan-type VisibleHead \stdClass&object{id: int, occurred_at: string}
 */
final readonly class EventFlapGroups
{
    public function assign(Event $event): void
    {
        if (! $this->eligible($event)) {
            return;
        }
        $query = Event::query();
        $query->getQuery()->where('check_id', $event->check_id)
            ->where('occurred_at', '>', $event->occurred_at->subHours(2)->format('Y-m-d H:i:s.uP'))
            ->where('occurred_at', '<=', $event->occurred_at->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(1);
        $previous = $query->first();
        if ($previous instanceof Event && $this->follows($event, $previous)) {
            $event->flap_group_id = $previous->flap_group_id ?? $previous->id;
        }
    }

    public function project(Event $event): void
    {
        $visible = Event::feedVisible();
        $visible->getQuery()->where('events.id', $event->id)->limit(1);
        if ($visible->getQuery()->exists()) {
            // Result recording holds the check lock: this INSERT is already the newest member.
            $this->storeHead($event->flap_group_id ?? $event->id, $event->id, $event->occurred_at);
        }
        if ($event->check_id === null || ! in_array($event->type, ['dns.records_changed', 'incident.opened', 'incident.resolved', 'incident.cause_changed'], true)) {
            return;
        }
        $affected = DB::table('events')->where('check_id', $event->check_id);
        $at = $event->occurred_at->format('Y-m-d H:i:s.uP');
        if ($event->type === 'incident.opened' && $event->incident_id !== null) {
            $firstFailure = DB::table('incidents')->where('id', $event->incident_id)->value('first_failure_at');
            if (! is_string($firstFailure)) {
                throw new \LogicException('Incident opening must retain its first failure time.');
            }
            $affected->whereBetween('occurred_at', [$firstFailure, $at]);
        } else {
            $affected->where('occurred_at', $at);
        }
        $days = config()->integer('monitoring.raw_retention_days');
        if ($days < 1) {
            throw new \LogicException('Activity retention must be positive.');
        }
        $since = $event->occurred_at->subDays($days)->format('Y-m-d H:i:s.uP');
        foreach ($affected->selectRaw('DISTINCT coalesce(flap_group_id, id) AS group_id')->pluck('group_id') as $groupId) {
            if (! is_int($groupId)) {
                throw new \LogicException('Event group identity must be an integer.');
            }
            // Separate ordered member and primary-key probes avoid sorting every member under the check lock.
            $members = Event::feedVisible()->getQuery()->select(['id', 'occurred_at'])
                ->where('flap_group_id', $groupId)->where('occurred_at', '>', $since)->where('occurred_at', '<=', $at)
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(1);
            $first = Event::feedVisible()->getQuery()->select(['id', 'occurred_at'])
                ->where('id', $groupId)->where('occurred_at', '>', $since)->where('occurred_at', '<=', $at)->limit(1);
            /** @var VisibleHead|null $latest */
            $latest = DB::query()->fromSub($members->unionAll($first), 'candidate')
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(1)->first();
            if ($latest === null) {
                // A group containing only setup/duplicate events has no feed row in this window.
                DB::table('event_flap_groups')->where('id', $groupId)->delete();

                continue;
            }
            $this->storeHead($groupId, $latest->id, CarbonImmutable::parse($latest->occurred_at));
        }
    }

    private function storeHead(int $groupId, int $eventId, CarbonImmutable $at): void
    {
        DB::table('event_flap_groups')->upsert([[
            'id' => $groupId, 'latest_event_id' => $eventId, 'last_at' => $at->format('Y-m-d H:i:s.uP'),
        ]], ['id'], ['latest_event_id', 'last_at']);
    }

    public function follows(Event $event, Event $previous): bool
    {
        return $this->eligible($event) && $this->eligible($previous)
            && $event->check_id === $previous->check_id
            && $event->from_result === $previous->to_result
            && $event->to_result === $previous->from_result
            && $event->occurred_at->greaterThanOrEqualTo($previous->occurred_at)
            && $event->occurred_at->lessThan($previous->occurred_at->addHours(2));
    }

    private function eligible(Event $event): bool
    {
        return $event->check_id !== null && $event->type === 'check.result_changed'
            && $event->component_type === null && $event->from_result !== null && $event->to_result !== null
            && $event->from_result !== CheckResult::Pending && $event->to_result !== CheckResult::Pending
            && $event->from_result !== $event->to_result;
    }
}

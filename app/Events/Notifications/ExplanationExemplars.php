<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Models\Check;
use Heptapod\Models\Event;

/**
 * Design §2.3: the events a check can emit, generated from the real mapping and never hand-listed. The explanation
 * picks the exemplars of one category; an empty list means "this check does not report that category".
 * Exemplars only feed routing steps 1 and 2; they never go through DeliveryAdmission.
 */
final readonly class ExplanationExemplars
{
    public function __construct(private CheckRegistry $registry) {}

    /** @return list<Event> */
    public function for(Check $check, NotificationCategory $category): array
    {
        return array_values(array_filter($this->all($check->type),
            static fn (Event $event): bool => NotificationCategory::of($event->setRelation('check', $check)) === $category));
    }

    /**
     * Every event of one check type that routing maps: incident openings and recoveries, result changes and the
     * declared addon events for each non-pending result and previous result.
     *
     * @return list<Event>
     */
    public function all(CheckKey $type): array
    {
        $check = new Check(['type' => $type]);
        $results = array_values(array_filter(CheckResult::cases(), static fn (CheckResult $result): bool => $result !== CheckResult::Pending));
        $events = [];
        foreach ([CheckResult::Failed, CheckResult::Errored] as $result) {
            $cause = $result === CheckResult::Errored ? IncidentCause::Errored : IncidentCause::Failed;
            $events[] = new Event(['type' => 'incident.opened', 'to_result' => $result, 'cause' => $cause]);
            $events[] = new Event(['type' => 'incident.resolved', 'from_result' => $result, 'to_result' => CheckResult::Succeeded, 'cause' => $cause]);
        }
        foreach ($results as $to) {
            foreach ($results as $from) {
                if ($from !== $to) {
                    $events[] = new Event(['type' => 'check.result_changed', 'from_result' => $from, 'to_result' => $to]);
                }
            }
        }
        $declared = in_array($type, $this->registry->keys(), true) ? $this->registry->get($type)->definition()->emittedEvents : [];
        foreach ($declared as $name) {
            foreach ($results as $to) {
                foreach ($results as $from) {
                    $events[] = new Event(['type' => $name, 'to_result' => $to,
                        'payload' => ['result' => $to->value, 'from_result' => $from->value]]);
                }
            }
        }
        $routable = [];
        foreach ($events as $event) {
            $event->setRelation('check', $check);
            if (NotificationCategory::of($event) instanceof NotificationCategory) {
                $routable[] = $event;
            }
        }

        return $routable;
    }
}

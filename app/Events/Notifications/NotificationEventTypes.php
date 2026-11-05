<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\Event;

final readonly class NotificationEventTypes
{
    public function __construct(private CheckRegistry $registry) {}

    /** @return list<string> */
    public function keys(): array
    {
        $keys = ['site.down', 'site.recovered', 'check.warning', 'check.failing', 'check.recovered'];
        foreach ($this->registry->keys() as $key) {
            $mapping = config()->array('notifications.event_types.'.$key->value, []);
            foreach ($this->registry->get($key)->definition()->emittedEvents as $event) {
                // A result-dependent mapping replaces its raw event; only its mapped keys below are routable.
                if (! is_array($mapping[$event] ?? null)) {
                    $keys[] = is_string($mapping[$event] ?? null) ? $mapping[$event] : $event;
                }
            }
            foreach ($mapping as $type) {
                foreach (is_array($type) ? $type : [$type] as $mapped) {
                    if (! is_string($mapped)) {
                        throw new \LogicException('Notification event mapping must contain strings.');
                    }
                    $keys[] = $mapped;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> */
    public function addonAlertKeys(CheckKey $type): array
    {
        if (! in_array($type, $this->registry->keys(), true)) {
            return [];
        }
        $check = new Check(['type' => $type]);
        $keys = [];
        foreach ($this->registry->get($type)->definition()->emittedEvents as $name) {
            foreach (CheckResult::cases() as $result) {
                if ($result === CheckResult::Pending) {
                    continue;
                }
                $event = new Event(['type' => $name, 'to_result' => $result,
                    'payload' => ['result' => $result->value, 'from_result' => CheckResult::Failed->value]]);
                $event->setRelation('check', $check);
                $key = $this->key($event);
                if ($key !== null && $this->severity($event) !== AlertSeverity::Recovered) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public function key(Event $event): ?string
    {
        $checkType = $event->check->type ?? $event->check_type;
        if ($checkType === null) {
            return $event->type === 'notification.digest' ? $event->type : null;
        }
        $uptime = $checkType === CheckKey::Uptime;
        $key = match ($event->type) {
            'incident.opened', 'incident.cause_changed', 'incident.still_down' => $uptime && $event->cause !== IncidentCause::Errored ? 'site.down' : 'check.failing',
            'incident.resolved' => $uptime ? 'site.recovered' : 'check.recovered',
            // Failed/errored alerts originate ONLY from threshold-qualified incident events.
            // A warning recovery of an incident already has its own resolved event.
            'check.result_changed' => $event->incident_id !== null && in_array($event->from_result, [CheckResult::Failed, CheckResult::Errored], true) ? null : match ($event->to_result) {
                CheckResult::Warning => 'check.warning',
                CheckResult::Succeeded => $event->from_result === CheckResult::Warning ? 'check.recovered' : null,
                default => null,
            },
            default => in_array($checkType, $this->registry->keys(), true)
                && in_array($event->type, $this->registry->get($checkType)->definition()->emittedEvents, true) ? $event->type : null,
        };
        if ($key === null) {
            return null;
        }
        $mapping = config()->array('notifications.event_types.'.$checkType->value, []);
        $mapped = $mapping[$key] ?? null;
        if (is_array($mapped)) {
            $result = $event->payload['result'] ?? $event->to_result?->value;
            $mapped = is_string($result) ? ($mapped[$result] ?? null) : null;
            if ($mapped === null) {
                // An unmapped result of a result-dependent event is not routable; never fall back to the raw key.
                return null;
            }
        }
        if ($mapped !== null && ! is_string($mapped)) {
            throw new \LogicException('Notification event mapping must be a string.');
        }

        if ($mapped === 'check.recovered' && ! in_array($event->type, ['incident.resolved', 'check.result_changed'], true)) {
            // Old addon payloads lack component history. Unknown/first results
            // are not recoveries; never invent a recovery notification.
            $from = $this->componentFrom($event);
            if (! in_array($from, [CheckResult::Warning, CheckResult::Failed, CheckResult::Errored], true)) {
                return null;
            }
        }

        return $mapped ?? $key;
    }

    public function severity(Event $event): AlertSeverity
    {
        if ($event->type === 'incident.resolved' || $this->key($event) === 'check.recovered' || $this->key($event) === 'site.recovered') {
            return AlertSeverity::Recovered;
        }
        // Component events use their own result, not the parent's aggregate result.
        $value = $event->payload['result'] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new \LogicException('Component notification result must be a string.');
        }
        $result = $value !== null ? CheckResult::from($value) : $event->to_result;

        return match ($result) {
            CheckResult::Failed => AlertSeverity::Down,
            CheckResult::Errored => AlertSeverity::Error,
            CheckResult::Warning => AlertSeverity::Warning,
            default => AlertSeverity::Info,
        };
    }

    public function critical(Event $event): bool
    {
        // Warnings/digests wait; outage reminders and incident recoveries bypass
        // quiet hours so an open failure is not hidden.
        return $event->type !== 'notification.digest'
            && ($event->type === 'incident.resolved' || in_array($this->severity($event), [AlertSeverity::Down, AlertSeverity::Error], true)
                || ($this->severity($event) === AlertSeverity::Recovered && in_array($this->componentFrom($event), [CheckResult::Failed, CheckResult::Errored], true)));
    }

    private function componentFrom(Event $event): ?CheckResult
    {
        $value = $event->payload['from_result'] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new \LogicException('Component previous result must be a string.');
        }

        return CheckResult::from($value);
    }
}

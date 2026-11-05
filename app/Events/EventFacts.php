<?php

declare(strict_types=1);

namespace Heptapod\Events;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\EngineMessage;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;

final readonly class EventFacts
{
    /** @param array<string, int|float|string|bool|null> $summaryParams */
    private function __construct(
        public ?string $componentName,
        public CarbonImmutable $time,
        public ?CarbonImmutable $failureStartedAt,
        public ?CarbonImmutable $recoveredAt,
        public ?int $durationSeconds,
        public ?string $summaryKey,
        public array $summaryParams,
    ) {}

    public static function fromEvent(Event $event, ?CheckRun $run = null): self
    {
        $component = $event->component_type;
        $name = $component === null ? null : ($event->payload['component_name'] ?? $event->payload['name']
            ?? __('events.component_identifier', ['id' => $event->component_id ?? throw new \LogicException('A component event must have an identifier.')]));
        if ($name !== null && ! is_string($name)) {
            throw new \LogicException('Component identity must be text.');
        }
        if ($name === '') {
            $name = __('events.component_identifier', ['id' => $event->component_id ?? throw new \LogicException('A component event must have an identifier.')]);
            if (! is_string($name)) {
                throw new \LogicException('Component identity must be text.');
            }
        }
        $started = $component !== null ? self::time($event, 'failure_started_at') : $event->incident?->first_failure_at;
        $recovered = $component !== null ? self::time($event, 'recovered_at') : ($event->type === 'incident.resolved' ? $event->occurred_at : null);
        $duration = null;
        if ($recovered !== null && $started !== null) {
            if ($started->greaterThan($recovered)) {
                throw new \LogicException('Recovery precedes failure.');
            }
            $duration = (int) $started->diffInSeconds($recovered);
        }
        $summary = $event->payload['summary'] ?? $run?->summary;
        $params = $event->payload['summary_params'] ?? ($run instanceof CheckRun ? $run->summary_params : null);
        $params ??= [];
        if (($summary !== null && ! is_string($summary)) || ! is_array($params)) {
            throw new \LogicException('Retained event summary is invalid.');
        }
        foreach ($params as $key => $value) {
            if (! is_string($key) || ! is_scalar($value) && $value !== null) {
                throw new \LogicException('Retained event summary parameters must be scalar.');
            }
        }

        /** @var array<string, int|float|string|bool|null> $params */
        if ($summary !== null) {
            // Every event consumer (notifications, mail, presenters, activity) reads retained summaries from here.
            ['key' => $summary, 'params' => $params] = EngineMessage::normalize($summary, $params);
        }

        return new self($name, $recovered ?? $event->occurred_at, $started, $recovered, $duration, $summary, $params);
    }

    private static function time(Event $event, string $key): ?CarbonImmutable
    {
        $value = $event->payload[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new \LogicException('Component event time must be an ISO timestamp.');
        }

        return CarbonImmutable::parse($value)->utc();
    }
}

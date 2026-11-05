<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Mail\MailEventFacts;

/** Retained facts only: presenters must not fetch current evidence or recompute historical results. */
final readonly class CheckRunPresentationInput
{
    public string $summary;

    /** @var array<string, string|int|float|bool|null> */
    public array $summaryParams;

    /**
     * Every presenter and mail renderer reads retained summaries through this object, so retained engine text is
     * normalized to its catalog cause here, where the input is built.
     *
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>|null  $metrics
     * @param  array<string, string|int|float|bool|null>  $summaryParams
     */
    public function __construct(
        public RunResult $result,
        public array $evidence,
        public ?array $metrics,
        string $summary,
        array $summaryParams,
        public ?MailEventFacts $event = null,
    ) {
        ['key' => $this->summary, 'params' => $this->summaryParams] = EngineMessage::normalize($summary, $summaryParams);
    }

    /** @return array<string, mixed> */
    public function observationEvidence(): array
    {
        return CheckRunEvidence::observation($this->evidence);
    }

    /** @return array<string, mixed>|null */
    public function eventPayload(string $type): ?array
    {
        if ($this->event?->type === $type) {
            return $this->event->payload;
        }
        $events = $this->evidence['presentation_events'] ?? [];
        if (! is_array($events)) {
            throw new \LogicException('Retained presentation events must be a map.');
        }
        $payload = $events[$type] ?? null;
        if ($payload === null) {
            return null; // Runs recorded before presentation facts were retained keep their original summary.
        }
        if (! is_array($payload) || array_any(array_keys($payload), static fn (mixed $key): bool => ! is_string($key))) {
            throw new \LogicException('Retained presentation event facts must be a named map.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}

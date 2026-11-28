<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** One server issue alert, localized: mail, the other channels, digests and Activity read it. */
final readonly class ServerIssueMessage
{
    /**
     * @param  string  $title  the issue on its server ("Disk almost full on web-1")
     * @param  string  $summary  one line for digests and untimed Activity summaries
     * @param  string  $lead  the opening's measurement and threshold, or how it resolved
     * @param  list<array{label: string, value: string}>  $facts
     * @param  string|null  $url  the server page; null once the server is removed
     * @param  array{key: string, params: array{time: string}}|null  $activitySummary  when Activity must localize a retained instant
     */
    public function __construct(public string $title, public string $summary, public string $lead, public array $facts, public ?string $url, public ?array $activitySummary = null) {}
}

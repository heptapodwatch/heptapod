<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Enums\HealthStatus;
use Heptapod\Models\SystemIssue;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SystemIssueData extends Data
{
    public readonly string $title_key;

    public readonly string $action_key;

    /** @param array<string, int|string|null> $details */
    public function __construct(
        public readonly SystemIssueKind $kind,
        public readonly string $scope,
        public readonly HealthStatus $severity,
        public readonly string $openedAt,
        public readonly array $details,
    ) {
        $this->title_key = $kind->titleKey($details);
        $this->action_key = $kind->actionKey($details);
    }

    /**
     * Down before Warn, then oldest first; the database order (opened_at, id) breaks ties, so the list never reorders between loads.
     *
     * @return list<self> at most one per kind and configured path, so the read is bounded
     */
    public static function open(): array
    {
        return array_values(SystemIssue::open()->get()
            ->sortBy(static fn (SystemIssue $issue): int => $issue->severity() === HealthStatus::Down ? 0 : 1)
            ->map(static fn (SystemIssue $issue): self => new self($issue->kind, $issue->scope, $issue->severity(), $issue->opened_at->toIso8601String(), $issue->details))
            ->all());
    }
}

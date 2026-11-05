<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

final class LighthouseAuditFailed extends \RuntimeException
{
    /** @param array<string, int> $stageSeconds */
    public function __construct(
        public readonly LighthouseFailureCode $failureCode,
        public readonly ?string $stage = null,
        public readonly ?string $diagnostic = null,
        public readonly ?int $budgetSeconds = null,
        public readonly ?string $lastUnrecognisedEvent = null,
        public readonly array $stageSeconds = [],
    ) {
        parent::__construct('Lighthouse audit did not complete.');
    }
}

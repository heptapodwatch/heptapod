<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Identifies a legacy URL or path whose entire query string must be stripped from public representations. */
final readonly class RedactedQueryTarget
{
    public function __construct(
        #[\SensitiveParameter] public string $target,
        public string $withoutQuery,
    ) {}
}

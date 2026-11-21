<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Allows an evidence DTO to redact diagnostic text while keeping protocol keys, reason codes and numeric facts intact. */
interface RedactsEvidence
{
    /**
     * @param  array<array-key, mixed>  $secrets
     * @param  array<array-key, mixed>  $urls
     * @return array<string, mixed>
     */
    public function redactedEvidence(#[\SensitiveParameter] array $secrets, array $urls = []): array;
}

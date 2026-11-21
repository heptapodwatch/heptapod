<?php

declare(strict_types=1);

namespace Heptapod\Addons\Uptime;

use Heptapod\Contracts\RedactsEvidence;
use Heptapod\Contracts\SecretRedactor;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UptimeEvidence extends Data implements RedactsEvidence
{
    /** @param list<string> $issues */
    public function __construct(
        public readonly ?int $status_code,
        public readonly array $issues,
        public readonly ?string $failure_kind = null,
        public readonly ?int $timeout_seconds = null,
        public readonly ?string $effective_url = null,
        public readonly ?string $expected_url = null,
        public readonly ?string $required_string = null,
        public readonly ?string $absent_string = null,
    ) {}

    public function redactedEvidence(#[\SensitiveParameter] array $secrets, array $urls = []): array
    {
        $redact = static fn (?string $value): ?string => $value === null ? null : SecretRedactor::redact($value, $secrets, $urls);

        return [
            'status_code' => $this->status_code,
            'issues' => $this->issues,
            'failure_kind' => $this->failure_kind,
            'timeout_seconds' => $this->timeout_seconds,
            'effective_url' => $redact($this->effective_url),
            'expected_url' => $redact($this->expected_url),
            'required_string' => $redact($this->required_string),
            'absent_string' => $redact($this->absent_string),
        ];
    }
}

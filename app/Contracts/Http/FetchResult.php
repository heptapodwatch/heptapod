<?php

namespace Heptapod\Contracts\Http;

final readonly class FetchResult
{
    /**
     * Accepted response bytes belong to the caller. Failures never carry bodies or headers.
     * Timings contain only the original cURL numeric metrics, in dispatch order.
     *
     * @param  array<array<string>>  $headers
     * @param  list<array<string, int|float>>  $transfers
     */
    public function __construct(
        public ?int $status = null,
        #[\SensitiveParameter] public string $body = '',
        #[\SensitiveParameter] public array $headers = [],
        public ?FetchFailure $failure = null,
        public array $transfers = [],
        #[\SensitiveParameter] public ?string $effectiveUrl = null,
        public ?CertificateInfo $certificate = null,
        public ?string $certificateUnavailableReason = null,
    ) {
        if ($failure instanceof FetchFailure && ($body !== '' || $headers !== [] || $effectiveUrl !== null || $certificate instanceof CertificateInfo)) {
            throw new \InvalidArgumentException('HTTP failure results cannot contain response payloads.');
        }
    }

    /** @return array{status: ?int, failure: ?FetchFailure} */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'failure' => $this->failure];
    }
}

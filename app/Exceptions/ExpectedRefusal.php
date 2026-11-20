<?php

declare(strict_types=1);

namespace Heptapod\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Expected user-reachable refusal rendered centrally in bootstrap/app.php and ErrorResponses.
 * Never reported as an unexpected server fault; carries a localized message key and optional retry timing.
 */
final class ExpectedRefusal extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, int|float|string>  $params
     * @param  'info'|'warning'|'error'  $tone
     * @param  array<string, int|string>  $recoveryRouteParams
     */
    public function __construct(
        public readonly int $status,
        public readonly string $messageKey,
        public readonly array $params = [],
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly string $tone = 'warning',
        public readonly ?string $titleKey = null,
        public readonly ?string $recoveryRoute = null,
        public readonly array $recoveryRouteParams = [],
        public readonly ?string $recoveryLabelKey = null,
    ) {
        parent::__construct($messageKey);
    }

    public function translatedMessage(): string
    {
        $translated = __($this->messageKey, $this->params);

        return is_string($translated) ? $translated : $this->messageKey;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->retryAfterSeconds !== null
            ? ['Retry-After' => (string) max(1, $this->retryAfterSeconds)]
            : [];
    }
}

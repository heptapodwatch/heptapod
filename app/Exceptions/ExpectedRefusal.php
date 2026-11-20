<?php

declare(strict_types=1);

namespace Heptapod\Exceptions;

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Expected user-reachable refusal rendered centrally in bootstrap/app.php and ErrorResponses.
 * Never reported as an unexpected server fault; carries a localized message key and optional retry timing.
 */
final class ExpectedRefusal extends RuntimeException implements HttpExceptionInterface, ShouldntReport
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

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers();
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->retryAfterSeconds !== null
            ? ['Retry-After' => (string) max(1, $this->retryAfterSeconds)]
            : [];
    }

    /**
     * Format a human-friendly retry time in the viewer's timezone ("14:07" + "in 4 min" / "in 25 sec").
     *
     * @return array{time: string, wait: string}
     */
    public static function cooldownParts(int $retryAfterSeconds, ?User $user = null): array
    {
        $seconds = max(1, $retryAfterSeconds);
        $timezone = $user instanceof User ? $user->resolvedTimezone() : 'UTC';
        $at = CarbonImmutable::now('UTC')->addSeconds($seconds)->setTimezone($timezone);

        if ($seconds < 60) {
            $wait = trans_choice('errors.wait_seconds', $seconds, ['count' => $seconds]);
        } else {
            $minutes = (int) ceil($seconds / 60);
            $wait = trans_choice('errors.wait_minutes', $minutes, ['count' => $minutes]);
        }

        return [
            'time' => $at->format('H:i'),
            'wait' => $wait,
        ];
    }
}

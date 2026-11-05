<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Only target TLS failures and HTTP 5xx responses share an uptime root cause. */
#[TypeScript]
enum AvailabilityFailure: string
{
    case Tls = 'tls';
    case Http5xx = 'http_5xx';

    public static function fromFetchFailure(?FetchFailure $failure): ?self
    {
        return match ($failure?->kind) {
            FetchFailureKind::Tls => self::Tls,
            FetchFailureKind::HttpStatus => self::fromStatus($failure->status),
            default => null,
        };
    }

    public static function fromStatus(?int $status): ?self
    {
        return $status !== null && $status >= 500 && $status <= 599 ? self::Http5xx : null;
    }
}

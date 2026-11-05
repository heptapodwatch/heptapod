<?php

declare(strict_types=1);

namespace Heptapod\Addons\Uptime;

use Heptapod\Contracts\CheckRunPresentationPartData;
use Symfony\Component\HttpFoundation\Response;

/** Historical and newly accepted HTTP summaries use the same protocol description; timing belongs to metrics. */
final class UptimeSummary
{
    /** @param array<string, string|int|float|bool|null> $params */
    public static function present(string $summary, array $params): CheckRunPresentationPartData
    {
        if (! in_array($summary, ['causes.http', 'causes.http_timing', 'uptime.http_reason'], true)) {
            return new CheckRunPresentationPartData($summary, $params);
        }
        $status = $params['status'] ?? null;
        if (! is_int($status) || $status < 100 || $status > 999) {
            throw new \LogicException('HTTP summary requires a valid integer status.');
        }
        // Non-standard HTTP codes have no standard reason phrase; preserve their numeric identifier.
        $reason = Response::$statusTexts[$status] ?? null;

        return $reason === null
            ? new CheckRunPresentationPartData('causes.http', ['status' => $status], shortKey: 'causes.http')
            : new CheckRunPresentationPartData('uptime.http_reason', ['status' => $status, 'reason' => $reason], shortKey: 'causes.http');
    }
}

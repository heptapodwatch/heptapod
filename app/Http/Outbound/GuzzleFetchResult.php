<?php

declare(strict_types=1);

namespace Heptapod\Http\Outbound;

use GuzzleHttp\TransferStats;
use Heptapod\Contracts\Http\CertificateInfo;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Psr\Http\Message\ResponseInterface;

final class GuzzleFetchResult
{
    public const array TIMINGS = ['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'];

    /** Restore DNS performed before cURL to its cumulative timing markers. */
    public static function withDnsTime(TransferStats $stats, float $dnsSeconds): TransferStats
    {
        if ($dnsSeconds === 0.0) {
            return $stats;
        }
        $values = $stats->getHandlerStats();
        $nativeDns = $values['namelookup_time'] ?? null;
        if (! is_int($nativeDns) && ! is_float($nativeDns)) {
            return $stats;
        }
        $values['namelookup_time'] = $nativeDns + $dnsSeconds;
        foreach (['connect_time', 'appconnect_time', 'pretransfer_time', 'starttransfer_time', 'total_time'] as $key) {
            $value = $values[$key] ?? null;
            if ((is_int($value) || is_float($value)) && $value > 0) {
                $values[$key] = max(0.0, $value + $dnsSeconds);
            }
        }
        $total = $stats->getTransferTime();

        return new TransferStats($stats->getRequest(), $stats->getResponse(), $total === null ? null : max(0.0, $total + $dnsSeconds), $stats->getHandlerErrorData(), $values);
    }

    /**
     * @param  list<array<string, int|float>>  $transfers
     * @param  array{Cert: string}|null  $certificateLeaf
     */
    public static function response(#[\SensitiveParameter] FetchRequest $request, #[\SensitiveParameter] ResponseInterface $response, array $transfers, #[\SensitiveParameter] ?string $effectiveUrl, ?array $certificateLeaf): FetchResult
    {
        $certificate = null;
        $certificateReason = null;
        if ($request->withCertificateInfo) {
            if (! str_starts_with($effectiveUrl ?? '', 'https://')) {
                $certificateReason = 'not_https';
            } elseif (! is_array($certificateLeaf)) {
                $certificateReason = 'not_available';
            } else {
                $certificate = CertificateInfo::fromCurl($certificateLeaf);
                $certificateReason = $certificate instanceof CertificateInfo ? null : 'invalid_certificate_info';
            }
        }
        $accepted = $request->acceptsStatus($response->getStatusCode());

        return new FetchResult(
            $response->getStatusCode(),
            $accepted ? (string) $response->getBody() : '',
            $accepted ? $response->getHeaders() : [],
            $accepted ? null : new FetchFailure(FetchFailureKind::HttpStatus, $response->getStatusCode()),
            $transfers,
            $accepted ? $effectiveUrl : null,
            $accepted ? $certificate : null,
            $certificateReason,
        );
    }
}

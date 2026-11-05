<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

final readonly class CertificateFetchResult
{
    /**
     * Display-only capture, leaf first. Native verify_peer + verify_peer_name handshake determines verified; PEM parsing must never establish trust.
     *
     * @param  list<string>  $pemChain
     */
    public function __construct(public array $pemChain, public string $hostname, public int $port, public bool $verified, public ?TlsFailureReason $failureReason = null) {}

    /** @return array{key: string, params: array<string, string>} */
    public function failureSummary(?TlsFailureReason $nativeReason = null): array
    {
        $reason = $this->failureReason ?? $nativeReason ?? TlsFailureReason::Handshake;
        $pem = $this->pemChain[0] ?? null;
        $leaf = is_string($pem) && strlen($pem) <= 65536 ? openssl_x509_parse($pem) : false;
        if (! is_array($leaf) || $this->verified) {
            return $reason->summary($this->hostname);
        }
        $extensions = $leaf['extensions'] ?? null;
        $subjectFields = $leaf['subject'] ?? null;
        $names = is_array($extensions) ? ($extensions['subjectAltName'] ?? null) : null;
        $commonName = is_array($subjectFields) ? ($subjectFields['CN'] ?? null) : null;
        // SANs include IP addresses and other identities; the failure summary lists DNS hosts only.
        $hosts = is_string($names)
            ? array_values(array_map(static fn (string $name): string => substr($name, 4),
                array_filter(array_map(trim(...), explode(',', $names)), static fn (string $name): bool => str_starts_with($name, 'DNS:'))))
            : (is_string($commonName) ? [$commonName] : []);
        $subject = $hosts === [] ? null : mb_substr(implode(', ', array_slice($hosts, 0, 3)), 0, 2048);
        $from = $leaf['validFrom_time_t'] ?? null;
        $until = $leaf['validTo_time_t'] ?? null;

        $summary = $reason->summary($this->hostname, $subject, is_int($from) ? gmdate('c', $from) : null, is_int($until) ? gmdate('c', $until) : null);
        if ($reason === TlsFailureReason::NameMismatch && count($hosts) > 3) {
            $summary['key'] .= '_more';
            $summary['params']['more'] = (string) (count($hosts) - 3);
        }

        return $summary;
    }
}

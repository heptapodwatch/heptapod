<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

final readonly class CertificateInfo
{
    /** @param array{Cert: string} $leaf */
    public static function fromCurl(array $leaf): ?self
    {
        $pem = $leaf['Cert'];
        if (strlen($pem) > 65536) {
            return null;
        }
        $certificate = @openssl_x509_parse($pem, false);
        if (! is_array($certificate)) {
            return null;
        }
        $issuerFields = $certificate['issuer'] ?? null;
        $subjectFields = $certificate['subject'] ?? null;
        $issuer = is_array($issuerFields) ? ($issuerFields['commonName'] ?? null) : null;
        $subject = is_array($subjectFields) ? ($subjectFields['commonName'] ?? null) : null;
        $from = $certificate['validFrom_time_t'] ?? null;
        $to = $certificate['validTo_time_t'] ?? null;
        if (! is_string($issuer) || ! is_string($subject) || ! is_int($from) || ! is_int($to)) {
            return null;
        }
        $extensions = $certificate['extensions'] ?? null;
        $names = is_array($extensions) ? ($extensions['subjectAltName'] ?? null) : null;

        return new self($issuer, $subject, is_string($names) ? $names : null, gmdate('c', $from), gmdate('c', $to));
    }

    public function __construct(
        public string $issuer,
        public string $subject,
        public ?string $subjectAlternativeNames,
        public string $validFrom,
        public string $validTo,
    ) {}
}

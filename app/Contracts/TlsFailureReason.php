<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Classifies native rejection diagnostics only; never decides certificate trust or hostname validity. */
enum TlsFailureReason: string
{
    case NameMismatch = 'name_mismatch';
    case Expired = 'expired';
    case SelfSigned = 'self_signed';
    case IncompleteChain = 'incomplete_chain';
    case NotYetValid = 'not_yet_valid';
    case DateInvalid = 'date_invalid';
    case Handshake = 'handshake';

    public static function fromNativeError(#[\SensitiveParameter] string $error): self
    {
        $error = strtolower($error);

        return match (true) {
            str_contains($error, 'did not match expected'), str_contains($error, 'no alternative certificate subject name'), str_contains($error, 'hostname mismatch'), str_contains($error, 'err_cert_common_name_invalid') => self::NameMismatch,
            str_contains($error, 'err_cert_date_invalid') => self::DateInvalid,
            str_contains($error, 'certificate has expired') => self::Expired,
            str_contains($error, 'certificate is not yet valid') => self::NotYetValid,
            str_contains($error, 'self-signed certificate'), str_contains($error, 'self signed certificate') => self::SelfSigned,
            str_contains($error, 'unable to get local issuer certificate'), str_contains($error, 'unable to verify the first certificate'), str_contains($error, 'unable to verify first certificate'), str_contains($error, 'err_cert_authority_invalid') => self::IncompleteChain,
            default => self::Handshake,
        };
    }

    /** Native verification remains authoritative; these facts explain an already rejected certificate.
     * @param  array<string, mixed>  $leaf
     */
    public static function forRejectedCertificate(array $leaf, self $nativeReason, bool $chainTrusted, int $now): self
    {
        $until = $leaf['validTo_time_t'] ?? null;
        $from = $leaf['validFrom_time_t'] ?? null;

        return match (true) {
            is_int($until) && $until < $now => self::Expired,
            is_int($from) && $from > $now => self::NotYetValid,
            $nativeReason === self::NameMismatch => self::NameMismatch,
            ! $chainTrusted && isset($leaf['issuer'], $leaf['subject']) && $leaf['issuer'] === $leaf['subject'] => self::SelfSigned,
            ! $chainTrusted => self::IncompleteChain,
            default => self::Handshake,
        };
    }

    /** @return array{key: string, params: array<string, string>} */
    public function summary(string $host, ?string $subject = null, ?string $validFrom = null, ?string $validUntil = null): array
    {
        $params = [];
        $suffix = $this->value;
        if ($this === self::NameMismatch && $subject !== null) {
            $suffix .= '_subject';
            $params = ['subject' => $subject, 'host' => $host];
        } elseif ($this === self::Expired && $validUntil !== null) {
            $suffix .= '_date';
            $params = ['date' => $validUntil];
        } elseif ($this === self::NotYetValid && $validFrom !== null) {
            $suffix .= '_date';
            $params = ['date' => $validFrom];
        }

        return ['key' => 'causes.tls.'.$suffix, 'params' => $params];
    }
}

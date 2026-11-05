<?php

declare(strict_types=1);

namespace Heptapod\Addons\CertificateHealth;

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckKey;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CertificateSnapshot extends CheckDetailData
{
    /**
     * @param  array{fingerprint: string, issuer: string, domain: string, additional_domains: list<string>, valid_from: string, valid_until: string, signature_algorithm: string, is_valid: bool}|null  $certificate_details
     * @param  list<array{type: string, passed: bool, label: string}>  $certificate_checks
     * @param  list<string>  $certificate_chain_issuers
     */
    public function __construct(
        public readonly ?array $certificate_details,
        public readonly array $certificate_checks,
        public readonly array $certificate_chain_issuers,
    ) {
        parent::__construct(CheckKey::CertificateHealth);
    }
}

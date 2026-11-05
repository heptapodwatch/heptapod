<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckKey;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DomainSnapshot extends CheckDetailData
{
    /**
     * @param  list<string>  $nameservers
     * @param  array<string, bool>  $domain_statuses
     * @param  array<string, mixed>|null  $rdap_domain_response
     */
    public function __construct(
        public readonly string $domain,
        public readonly ?string $registrar,
        public readonly ?string $expires_at,
        public readonly ?string $registered_at,
        public readonly ?string $last_changed_at,
        public readonly ?string $last_updated_in_rdap_db_at,
        public readonly string $created_at,
        public readonly array $nameservers,
        #[LiteralTypeScriptType('Record<string, boolean>')]
        public readonly array $domain_statuses,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?array $rdap_domain_response,
        public readonly string $date_precision,
        public readonly string $source,
        public readonly string $server,
        public readonly ?string $not_supported_reason,
    ) {
        parent::__construct(CheckKey::Domain);
    }
}

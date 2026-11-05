<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class InvitationPageData extends Data
{
    /** @param 'valid'|'unavailable'|'expired'|'active-account' $state */
    public function __construct(
        public readonly string $instance,
        #[LiteralTypeScriptType("'valid' | 'unavailable' | 'expired' | 'active-account'")]
        public readonly string $state,
        public readonly ?InvitationData $invitation,
        public readonly Optional|string $token = new Optional,
        public readonly Optional|string $passwordRules = new Optional,
    ) {}
}

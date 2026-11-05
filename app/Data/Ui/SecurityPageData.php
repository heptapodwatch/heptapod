<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SecurityPageData extends Data
{
    /** @param list<array{id: int, name: string, authenticator: string|null, created_at_diff: string|null, last_used_at_diff: string|null}> $passkeys */
    public function __construct(
        public readonly bool $canManageTwoFactor,
        public readonly bool $canManagePasskeys,
        public readonly array $passkeys,
        public readonly string $passwordRules,
        public readonly Optional|bool $twoFactorEnabled = new Optional,
        public readonly Optional|bool $requiresConfirmation = new Optional,
    ) {}
}

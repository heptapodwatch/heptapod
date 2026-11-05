<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class InvitationData extends Data
{
    /** @param list<array{id: int, name: string, sites_count: int}> $clients */
    public function __construct(
        public readonly string $instance,
        public readonly ?string $inviter,
        public readonly string $email,
        public readonly string $role,
        public readonly string $client_scope,
        public readonly array $clients,
        public readonly string $expires_at,
    ) {}
}

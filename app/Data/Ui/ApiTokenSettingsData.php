<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ApiTokenSettingsData extends Data
{
    /**
     * @param  list<array{id: int, name: string, abilities: list<string>, client_id: int|null, client_name: string|null, created_at: string|null, last_used_at: string|null, expires_at: string|null}>  $tokens
     * @param  list<string>  $abilities
     * @param  array{read: list<string>, write: list<string>, manage: list<string>, agent-read: list<string>}  $presets
     * @param  list<array{id: int, name: string, short_name: string|null, accent_color: string|null, avatar: ClientAvatarData, is_internal: bool}>  $clients
     */
    public function __construct(
        public readonly array $tokens,
        public readonly array $abilities,
        public readonly array $presets,
        public readonly array $clients,
        public readonly string $timezone,
    ) {}
}

<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Actions\ResolveClientAvatar;
use Heptapod\Models\Client;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ClientData extends Data
{
    public static function fromClient(Client $client): self
    {
        $avatar = app(ResolveClientAvatar::class)($client);

        return new self($client->id, $client->displayName(), $client->locale, $client->timezone, $client->short_name,
            $client->contact_name, $client->accent_color, $client->archived_at?->toIso8601String(), $client->is_internal, $avatar, $client->avatar_site_id);
    }

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $locale,
        public readonly string $timezone,
        public readonly ?string $short_name = null,
        public readonly ?string $contact_name = null,
        public readonly ?string $accent_color = null,
        public readonly ?string $archived_at = null,
        public readonly bool $is_internal = false,
        public readonly ClientAvatarData $avatar = new ClientAvatarData,
        public readonly ?int $avatar_site_id = null,
    ) {}
}

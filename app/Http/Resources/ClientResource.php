<?php

declare(strict_types=1);

namespace Heptapod\Http\Resources;

use Heptapod\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ClientResource extends JsonResource
{
    #[\Override]
    public static $wrap;

    /** @return array{id: int, name: string, short_name: string|null, locale: string, timezone: string|null, accent_color: string|null, is_internal: bool, archived: bool, archived_at: string|null, created_at: string|null, updated_at: string|null} */
    public function toArray(Request $request): array
    {
        /** @var Client $client */
        $client = $this->resource;

        return ['id' => $client->id, 'name' => $client->name, 'short_name' => $client->short_name, 'locale' => $client->locale, 'timezone' => $client->timezone,
            'accent_color' => $client->accent_color, 'is_internal' => $client->is_internal, 'archived' => $client->archived_at !== null,
            'archived_at' => $client->archived_at?->utc()->format('Y-m-d H:i:s'),
            'created_at' => $client->created_at?->utc()->format('Y-m-d H:i:s'), 'updated_at' => $client->updated_at?->utc()->format('Y-m-d H:i:s')];
    }
}

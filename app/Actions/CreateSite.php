<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Data\Ui\SiteProbeData;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CreateSite
{
    public function __construct(private CheckRegistry $registry, private UpdateSite $updateSite) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(int $clientId, array $attributes, ?SiteProbeData $detection = null): Site
    {
        return DB::transaction(function () use ($clientId, $attributes, $detection): Site {
            $clients = Client::query();
            NotificationLocks::forShare($clients->getQuery()->where('id', $clientId));
            $client = $clients->firstOrFail();
            if ($client->archived_at !== null) {
                throw ValidationException::withMessages(['client_id' => __('clients.archived_cannot_add_site')]);
            }
            $site = new Site(['client_id' => $clientId, 'badge_id' => (string) Str::uuid()]);
            if (! array_key_exists('checks', $attributes)) {
                $attributes['checks'] = array_map(fn (CheckKey $key): string => $key->value, array_values(array_filter($this->registry->keys(), function (CheckKey $key) use ($attributes, $detection): bool {
                    $definition = $this->registry->get($key)->definition();

                    return $this->registry->availability($key)->reason === null && ($detection instanceof SiteProbeData ? in_array($key, $detection->recommended_checks, true) : $definition->recommended) && (! $definition->requiresHttps || is_string($attributes['url'] ?? null) && str_starts_with($attributes['url'], 'https://'));
                })));
            }

            return ($this->updateSite)($site, $attributes);
        });
    }
}

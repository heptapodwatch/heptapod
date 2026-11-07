<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\NormaliseSiteUrl;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Signature('heptapod:sites:duplicates')]
final class SiteDuplicatesCommand extends Command
{
    public function getDescription(): string
    {
        return __('sites.url_duplicates_description');
    }

    public function handle(NormaliseSiteUrl $normalise): int
    {
        $groups = [];
        foreach (DB::table('sites')->join('clients', 'clients.id', '=', 'sites.client_id')
            ->select('sites.id', 'sites.url', 'clients.name as client')->lazyById(500, 'sites.id', 'id') as $site) {
            /** @var \stdClass&object{id: int, url: string, client: string} $site */
            try {
                $groups[$normalise->key($site->url)][] = [$site->id, $site->url, $site->client];
            } catch (ValidationException) {
                continue;
            }
        }
        $collisions = array_filter($groups, static fn (array $sites): bool => count($sites) > 1);
        foreach ($collisions as $sites) {
            $this->table(['id', 'url', 'client'], $sites);
        }
        if ($collisions === []) {
            $this->info(__('sites.url_duplicates_none'));
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Contracts\DirectorySiteData;
use Heptapod\Contracts\ListPaging;
use Heptapod\Contracts\PaginationData;
use Heptapod\Contracts\SiteDirectory;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/** Two reads each: the projected sites (visibility first, as the search does) and their clients' names. */
final readonly class SiteDirectoryReader implements SiteDirectory
{
    public function __construct(private SitesReader $sites, private ListPaging $paging) {}

    public function visible(Authenticatable $user, ?array $siteIds = null): array
    {
        if ($siteIds === []) {
            return [];
        }

        return $this->rows(array_values($this->query($user, $siteIds)->get()->all()));
    }

    public function page(Authenticatable $user, array $siteIds, string $list): array
    {
        $page = $this->paging->paginate($this->query($user, $siteIds), $list);
        /** @var list<Site> $items */
        $items = array_values($page->items());

        return ['sites' => $this->rows($items), 'pagination' => PaginationData::fromPaginator($page)];
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return Builder<Site>
     */
    private function query(Authenticatable $user, ?array $siteIds): Builder
    {
        if (! $user instanceof User) {
            throw new \LogicException('Only app users see sites.');
        }
        $sites = $this->sites->projectedForSearch($user);
        if ($siteIds !== null) {
            $sites->getQuery()->whereIntegerInRaw('id', $siteIds);
        }
        $this->sites->orderByStatus($sites);
        $sites->getQuery()->orderBy('id');

        return $sites;
    }

    /**
     * @param  list<Site>  $sites
     * @return list<DirectorySiteData>
     */
    private function rows(array $sites): array
    {
        $clientQuery = Client::query();
        // No name for "No client".
        $clientQuery->getQuery()->whereIn('id', array_unique(array_map(static fn (Site $site): int => $site->client_id, $sites)))->where('is_internal', false);
        /** @var array<int, string> $clientNames */
        $clientNames = $clientQuery->get(['id', 'name'])->mapWithKeys(static fn (Client $client): array => [$client->id => $client->name])->all();

        return array_map(static function (Site $site) use ($clientNames): DirectorySiteData {
            $name = $site->getAttribute('name');
            $state = $site->getAttribute('state');
            if (! is_string($name) || ! is_string($state)) {
                throw new \LogicException('Site projection must contain its name and state.');
            }

            return new DirectorySiteData($site->id, $name, $site->url, $state, $clientNames[$site->client_id] ?? null);
        }, $sites);
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\SearchResultsData;
use Heptapod\Data\Ui\SearchSiteData;
use Heptapod\Enums\SiteStatus;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Query\Builder;

final readonly class SearchReader
{
    public function __construct(private SitesReader $sites, private IncidentsReader $incidents, private SitePolicy $policy) {}

    public function read(User $user, string $search): SearchResultsData
    {
        $clients = Client::forBrowsing($this->policy->visibleClients($user));
        if ($search === '') {
            return new SearchResultsData([], [], []);
        }
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
        $sites = $this->sites->projectedForSearch($user);
        $sites->getQuery()->where(static function (Builder $query) use ($pattern): void {
            $query->where('name', 'ilike', $pattern)->orWhere('url', 'ilike', $pattern);
        });
        $this->sites->orderByStatus($sites);
        $sites->getQuery()->orderBy('id')->limit(10);
        $siteRows = $sites->get();
        $clientQuery = Client::query();
        $clientQuery->getQuery()->whereIn('id', $siteRows->pluck('client_id')->unique()->all());
        /** @var array<int, string> $clientNames */
        $clientNames = $clientQuery->pluck('name', 'id')->all();
        $rows = [];
        foreach ($siteRows as $site) {
            $name = $site->getAttribute('name');
            $state = $site->getAttribute('state');
            if (! is_string($name) || ! is_string($state)) {
                throw new \LogicException('Site projection must contain its name and state.');
            }
            $rows[] = new SearchSiteData($site->id, $name, $site->url, $site->client_id, SiteStatus::from($state), $site->iconUrl(), $site->icon_backing, $clientNames[$site->client_id] ?? null);
        }
        $clients->getQuery()->where('name', 'ilike', $pattern)->orderBy('name')->orderBy('id')->limit(10);
        $clientRows = $clients->with(Client::AvatarRelations)->get()->map(static fn (Client $client): ClientData => ClientData::fromClient($client))->all();
        $incidentId = null;
        if (preg_match('/^INC-([1-9][0-9]*)$/iD', $search, $matches) === 1) {
            $parsedId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $incidentId = is_int($parsedId) ? $parsedId : null;
        }
        $incidents = $this->incidents->scopedForSearch($user, $pattern, $incidentId);
        $incidents->getQuery()->orderByDesc('opened_at')->orderByDesc('id')->limit(10);

        return new SearchResultsData($rows, array_values($clientRows), $this->incidents->presentedRows($incidents->get()));
    }
}

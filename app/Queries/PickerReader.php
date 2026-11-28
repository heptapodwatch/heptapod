<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\PickerResultsData;
use Heptapod\Data\Ui\PickerSiteData;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Query\Builder;

/**
 * The reads behind the server-searched site and client pickers: the visible entities matching a search, at most Limit
 * of them, or the chosen ones by id so a picker can name its value without loading every row.
 */
final readonly class PickerReader
{
    public const int Limit = 20;

    /** The most chosen values one read names (a multi picker's chips). */
    public const int IdLimit = 100;

    public function __construct(private SitePolicy $policy) {}

    /**
     * A search leaves out sites whose deletion is pending; a chosen one still resolves by id. $withIcon keeps only sites
     * with a fetched icon (a client's avatar choice).
     *
     * @param  list<int>|null  $ids
     */
    public function sites(User $user, string $search, ?array $ids, ?int $clientId, bool $withIcon): PickerResultsData
    {
        $sites = Site::query()->with('client');
        $query = $sites->getQuery();
        $query->whereIntegerInRaw('sites.id', $this->policy->visibleSiteIds($user));
        if ($clientId !== null) {
            $query->where('sites.client_id', $clientId);
        }
        if ($withIcon) {
            $query->whereNotNull('sites.icon_hash');
        }
        if ($ids !== null) {
            $query->whereIntegerInRaw('sites.id', $ids);
        } else {
            $query->whereNull('sites.marked_for_deletion_at');
            // Every word matches the name, the URL or the client name, as the pickers matched before they searched on the server.
            foreach ($this->patterns($search) as $pattern) {
                $query->where(static fn (Builder $match): Builder => $match->whereRaw(Site::displayNameSql().' ILIKE ?', [$pattern])->orWhere('sites.url', 'ilike', $pattern)
                    ->orWhereIn('sites.client_id', static fn (Builder $clients): Builder => $clients->select('clients.id')->from('clients')
                        ->where('clients.is_internal', false)->where('clients.name', 'ilike', $pattern)));
            }
            $query->limit(self::Limit + 1);
        }
        $query->orderBy(SitesReader::collated(Site::displayNameSql()))->orderBy('sites.id');
        $rows = $sites->get();

        return new PickerResultsData(array_values($rows->take(self::Limit)->map(static function (Site $site): PickerSiteData {
            $client = $site->client ?? throw new \LogicException('Every site belongs to a client.');
            $host = parse_url($site->url, PHP_URL_HOST);

            return new PickerSiteData($site->id, $site->displayName(), is_string($host) ? $host : $site->url, $client->id, $client->is_internal ? null : $client->name,
                $client->resolvedTimezone(), $site->iconUrl(), $site->icon_backing);
        })->all()), $ids === null && $rows->count() > self::Limit);
    }

    /**
     * $browse leaves out an Internal client without sites, as list filters do (Client::forBrowsing).
     *
     * @param  list<int>|null  $ids
     */
    public function clients(User $user, string $search, ?array $ids, bool $includeArchived, bool $browse): PickerResultsData
    {
        $clients = $this->policy->visibleClients($user, $includeArchived)->with(Client::AvatarRelations);
        if ($browse) {
            Client::forBrowsing($clients);
        }
        $query = $clients->getQuery();
        if ($ids !== null) {
            $query->whereIntegerInRaw('clients.id', $ids);
        } else {
            foreach ($this->patterns($search) as $pattern) {
                $query->whereRaw(Client::displayNameSql().' ILIKE ?', [__('No client'), $pattern]);
            }
            $query->limit(self::Limit + 1);
        }
        $query->orderByDesc('clients.is_internal')->orderBy(SitesReader::collated('clients.name'))->orderBy('clients.id');
        $rows = $clients->get();

        return new PickerResultsData(array_values($rows->take(self::Limit)->map(static fn (Client $client): ClientData => ClientData::fromClient($client))->all()),
            $ids === null && $rows->count() > self::Limit);
    }

    /**
     * One case-insensitive match pattern per word of the search, at most five words.
     *
     * @return list<string>
     */
    private function patterns(string $search): array
    {
        $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            throw new \LogicException('A search splits into words.');
        }
        $words = array_slice($words, 0, 5);

        return array_map(static fn (string $word): string => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%', $words);
    }
}

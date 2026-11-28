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
use Heptapod\Settings\InstanceSettings;

/**
 * The reads behind the server-searched site and client pickers: the visible entities matching a search, at most Limit
 * of them, or the chosen ones by id (every one the user can see) so a picker names its values without loading every row.
 */
final readonly class PickerReader
{
    public const int Limit = 20;

    /** The most chosen values one read names; a channel saves at most this many site exceptions in one edit. */
    public const int IdLimit = 500;

    public function __construct(private SitePolicy $policy, private InstanceSettings $settings) {}

    /**
     * One query: the visibility predicate, the search and the client columns read together, so a site never shows a
     * client the user cannot see. A search leaves out sites whose deletion is pending; a chosen one still resolves by id.
     * $withIcon keeps only sites with a fetched icon (a client's avatar choice); $includeArchived adds archived clients'
     * sites where a picker offered them before (maintenance, a client's own exceptions).
     *
     * @param  list<int>|null  $ids
     */
    public function sites(User $user, string $search, ?array $ids, ?int $clientId, bool $withIcon, bool $includeArchived): PickerResultsData
    {
        $sites = $this->policy->visibleSites($user, $includeArchived);
        $query = $sites->getQuery();
        $query->join('clients', 'clients.id', '=', 'sites.client_id')
            ->addSelect(['clients.name as picker_client_name', 'clients.is_internal as picker_client_internal', 'clients.timezone as picker_client_timezone']);
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
            SiteSearch::whereMatches($query, $search);
            $query->limit(self::Limit + 1);
        }
        $query->orderBy(SitesReader::collated(Site::displayNameSql()))->orderBy('sites.id');
        $rows = array_values($sites->get()->all());
        $more = $ids === null && count($rows) > self::Limit;
        $rows = $ids === null ? array_slice($rows, 0, self::Limit) : $rows;
        $clientNames = [];
        foreach ($rows as $site) {
            $name = $site->getAttribute('picker_client_name');
            $clientNames[$site->id] = $site->getAttribute('picker_client_internal') === true ? __('No client')
                : (is_string($name) ? $name : throw new \LogicException('A site query must select its client name.'));
        }

        return new PickerResultsData(array_map(function (Site $site) use ($clientNames): PickerSiteData {
            $host = parse_url($site->url, PHP_URL_HOST);
            $timezone = $site->getAttribute('picker_client_timezone');

            return new PickerSiteData($site->id, $site->displayName(), is_string($host) ? $host : $site->url, $site->client_id, $clientNames[$site->id],
                $site->getAttribute('picker_client_internal') === true,
                is_string($timezone) ? $timezone : $this->settings->timezone, $site->iconUrl(), $site->icon_backing,
                // The monitored address without its scheme is unique per site (url_key), so the detail never depends on what else is listed.
                $clientNames[$site->id].' · '.(preg_replace('~^https?://~i', '', $site->url) ?? throw new \LogicException('Invalid site URL pattern.')));
        }, $rows), $more);
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
            SiteSearch::whereClientMatches($query, $search);
            $query->limit(self::Limit + 1);
        }
        $query->orderByDesc('clients.is_internal')->orderBy(SitesReader::collated('clients.name'))->orderBy('clients.id');
        $rows = array_values($clients->get()->all());
        $more = $ids === null && count($rows) > self::Limit;

        return new PickerResultsData(array_map(static fn (Client $client): ClientData => ClientData::fromClient($client),
            $ids === null ? array_slice($rows, 0, self::Limit) : $rows), $more);
    }
}

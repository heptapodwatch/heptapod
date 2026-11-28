<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Contracts\ListFilters;
use Heptapod\Contracts\ListPaging;
use Heptapod\Contracts\PaginationData;
use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\ClientRowData;
use Heptapod\Data\Ui\DashboardTilesData;
use Heptapod\Data\Ui\IncidentData;
use Heptapod\Data\Ui\SiteRowData;
use Heptapod\Enums\SiteStatus;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class ClientsReader
{
    public function __construct(private SitesReader $sites, private DashboardSummaryReader $summaries, private IncidentsReader $incidents, private SitePolicy $policy, private ListPaging $paging) {}

    /** @return array{list<ClientRowData>, PaginationData} */
    public function index(User $user, ListFilters $filters): array
    {
        $archived = $filters->value('state') === 'archived';
        $clients = Client::forBrowsing($this->policy->visibleClients($user, $archived));
        if (! $archived) {
            $ranked = DB::query()->fromSub($this->sites->projected($user), 'scoped_sites')
                ->select('client_id')
                ->selectRaw('min('.SiteStatus::attentionRankSql('state').') AS attention_rank')
                ->groupBy('client_id');
            $clients->getQuery()->leftJoinSub($ranked, 'attention', 'attention.client_id', '=', 'clients.id');
        }
        $query = $filters->query($clients);
        $query->getEloquentBuilder()->with(Client::AvatarRelations);
        if (! $archived) {
            $query->getEloquentBuilder()->getQuery()->orderByRaw('coalesce(attention.attention_rank, '.SiteStatus::quietRankLiteral().')');
        }
        $query->getEloquentBuilder()->getQuery()->orderBy(SitesReader::collated('clients.name'))->orderBy('clients.id');
        $page = $this->paging->paginate($query->getEloquentBuilder(), 'clients', 'page');
        /** @var list<int> $ids */
        $ids = $page->getCollection()->pluck('id')->all();
        $summaries = $this->summaries->read($user, $ids, includeArchived: $archived, uptimeDays: 30);
        $rows = [];
        /** @var Client $client */
        foreach ($page as $client) {
            $rows[] = new ClientRowData(ClientData::fromClient($client), $summaries[$client->id]);
        }

        return [$rows, PaginationData::fromPaginator($page)];
    }

    public function client(User $user, int $id): ClientData
    {
        $clients = $this->policy->visibleClients($user, includeArchived: true);
        $clients->getQuery()->where('id', $id);
        $client = $clients->firstOrFail()->load(Client::AvatarRelations);

        return ClientData::fromClient($client);
    }

    public function summary(User $user, int $id): DashboardTilesData
    {
        return $this->summaries->read($user, [$id], includeArchived: true, uptimeDays: 30)[$id];
    }

    /** @return array{sites: list<SiteRowData>, pagination: PaginationData} */
    public function sitePage(User $user, int $id): array
    {
        $sites = $this->sites->projected($user, includeArchived: true);
        $sites->getQuery()->where('client_id', $id);
        $this->sites->orderByStatus($sites);
        $sites->getQuery()->orderBy('sites.id');
        $page = $this->paging->paginate($sites, 'client_sites', 'page');
        /** @var Collection<int, Site> $rows */
        $rows = $page->getCollection();

        return ['sites' => $this->sites->rows($rows),
            'pagination' => PaginationData::fromPaginator($page)];
    }

    /** @return list<IncidentData> */
    public function incidents(User $user, int $id): array
    {
        $incidents = $this->incidents->scoped($user, includeArchived: true)->whereHas('site', static function (Builder $query) use ($id): void {
            $query->getQuery()->where('client_id', $id);
        });
        $incidents->getQuery()->orderByDesc('opened_at')->orderByDesc('id')->limit(20);

        return $this->incidents->presentedRows($incidents->get());
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\ClientRowData;
use Heptapod\Data\Ui\ClientsIndexPageData;
use Heptapod\Data\Ui\DashboardTilesData;
use Heptapod\Data\Ui\IncidentData;
use Heptapod\Data\Ui\PaginationData;
use Heptapod\Data\Ui\SiteRowData;
use Heptapod\Enums\SiteStatus;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ClientsReader
{
    public function __construct(private SitesReader $sites, private DashboardSummaryReader $summaries, private IncidentsReader $incidents, private SitePolicy $policy) {}

    public function index(User $user, Request $request): ClientsIndexPageData
    {
        $archived = $request->input('filter.state') === 'archived';
        $clients = Client::forBrowsing($this->policy->visibleClients($user, $archived));
        if (! $archived) {
            $ranked = DB::query()->fromSub($this->sites->projected($user), 'scoped_sites')
                ->select('client_id')
                ->selectRaw('min('.SiteStatus::attentionRankSql('state').') AS attention_rank')
                ->groupBy('client_id');
            $clients->getQuery()->leftJoinSub($ranked, 'attention', 'attention.client_id', '=', 'clients.id');
        }
        $query = QueryBuilder::for($clients, $request)->allowedFilters(AllowedFilter::partial('search', 'clients.name'), AllowedFilter::callback('state', static function (Builder $query, mixed $value): void {
            if ($value === 'archived') {
                $query->getQuery()->whereNotNull('clients.archived_at');
            } elseif ($value === 'attention') {
                $query->getQuery()->where('attention.attention_rank', '<=', SiteStatus::quietRank() - 1);
            } elseif ($value === 'all') {
                $query->getQuery()->whereNull('clients.archived_at');
            } else {
                throw new \LogicException('Client state filter must be validated.');
            }
        }));
        $query->getEloquentBuilder()->with(Client::AvatarRelations);
        $collation = SitesReader::collation();
        if (! $archived) {
            $query->getEloquentBuilder()->getQuery()->orderByRaw('coalesce(attention.attention_rank, '.SiteStatus::quietRankLiteral().')');
        }
        $query->getEloquentBuilder()->getQuery()->orderByRaw('clients.name COLLATE "'.$collation.'"')->orderBy('clients.id');
        $page = $query->paginate(50)->withQueryString();
        /** @var list<int> $ids */
        $ids = $page->getCollection()->pluck('id')->all();
        $summaries = $this->summaries->read($user, $ids, includeArchived: $archived, uptimeDays: 30);
        $rows = [];
        foreach ($page as $client) {
            $rows[] = new ClientRowData(ClientData::fromClient($client), $summaries[$client->id]);
        }

        return new ClientsIndexPageData($rows, new PaginationData($page->currentPage(), $page->perPage(), $page->total(), $page->lastPage(), $page->nextPageUrl(), $page->previousPageUrl()),
            $request->filled('filter.search') ? $request->string('filter.search')->toString() : null, $request->string('filter.state', 'all')->toString(), $user->hasAnyRole(['Owner', 'Admin']));
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
        $page = $sites->paginate(50)->withQueryString();

        return ['sites' => $this->sites->rows($page->getCollection()),
            'pagination' => new PaginationData($page->currentPage(), $page->perPage(), $page->total(), $page->lastPage(), $page->nextPageUrl(), $page->previousPageUrl())];
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

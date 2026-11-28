<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\FilterKind;
use Heptapod\Contracts\ListFilters;
use Heptapod\Contracts\PaginationData;
use Heptapod\Data\Ui\DashboardTilesData;
use Heptapod\Data\Ui\SiteListSummaryData;
use Heptapod\Data\Ui\SitesIndexPageData;
use Heptapod\Enums\SiteStatus;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\User;
use Heptapod\Queries\DashboardSummaryReader;
use Heptapod\Queries\SitesReader;
use Heptapod\Queries\TagsReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;
use Spatie\QueryBuilder\AllowedFilter;

final class SitesIndexController extends Controller
{
    public function __invoke(Request $request, SitesReader $reader, DashboardSummaryReader $summaries, TagsReader $tags): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasAnyRole(['Owner', 'Admin', 'Member']), 403);
        $filters = ListFilters::read($request, [
            'search' => ['predicate' => static fn (string $key): AllowedFilter => AllowedFilter::partial($key, 'name'),
                'kind' => FilterKind::Search, 'label' => 'Search sites', 'rules' => ['nullable', 'string', 'max:255']],
            'client_id' => ['predicate' => AllowedFilter::exact(...),
                'kind' => FilterKind::Combobox, 'label' => 'Client', 'empty_label' => 'All clients', 'source' => 'clients', 'rules' => ['nullable', 'integer', 'min:1']],
            'state' => ['predicate' => AllowedFilter::exact(...),
                'kind' => FilterKind::Select, 'label' => 'Status', 'empty_label' => 'All statuses', 'rules' => ['nullable'], 'enum' => SiteStatus::class,
                'options' => ['ok' => 'OK', 'warning' => 'Warning', 'down' => 'Down', 'paused' => 'Paused', 'pending' => 'Pending', 'disabled' => 'Off', 'unavailable' => 'Unavailable', 'maintenance' => 'Maintenance', 'failed' => 'Failed', 'check-error' => 'Check error']],
            'check' => ['predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value): void {
                if (! is_string($value)) {
                    throw new \LogicException('Check filter must be validated as a string.');
                }
                $query->whereHas('checks', static function (Builder $checks) use ($value): void {
                    $checks->where('type', $value)->where('enabled', true);
                });
            }),
                'kind' => FilterKind::Select, 'label' => 'Check', 'empty_label' => 'All checks', 'rules' => ['nullable'], 'enum' => CheckKey::class, 'options' => ListFilters::checkOptions()],
            'tag' => ['predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value): void {
                if (! is_string($value)) {
                    throw new \LogicException('Tag filter must be validated as a string.');
                }
                $query->whereHas('tags', static function (Builder $tags) use ($value): void {
                    $tags->getQuery()->where('name->en', $value);
                });
            }),
                'kind' => FilterKind::Combobox, 'label' => 'Tag', 'empty_label' => 'All tags', 'source' => 'tags', 'prefix' => 'tag:', 'rules' => ['nullable', 'string', 'max:255']],
            'advisory' => ['predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($reader): void {
                $reader->advisoryFilter($query, advisoryId: is_string($value) ? $value : throw new \LogicException('Advisory filter must be validated as a string.'));
            })->delimiter(''),
                'kind' => FilterKind::Combobox, 'label' => 'Security advisories', 'empty_label' => 'All advisories', 'source' => 'advisories', 'group' => 'advisories', 'prefix' => 'advisory:', 'rules' => ['nullable', 'string', 'max:2048']],
            'package' => ['predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($reader): void {
                $reader->advisoryFilter($query, package: is_string($value) ? $value : throw new \LogicException('Package filter must be validated as a string.'));
            })->delimiter(''),
                'kind' => FilterKind::Combobox, 'label' => 'Security advisories', 'group' => 'advisories', 'prefix' => 'package:', 'rules' => ['nullable', 'string', 'max:255']],
            'advisories' => ['predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($reader): void {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    $reader->advisoryFilter($query);
                }
            }),
                'kind' => FilterKind::Combobox, 'label' => 'Security advisories', 'group' => 'advisories', 'prefix' => 'any:', 'rules' => ['boolean']],
            'attention' => ['predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value): void {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    $query->getQuery()->whereIn('state', SiteStatus::attention());
                }
            }),
                'kind' => FilterKind::Chip, 'label' => 'Needs attention', 'rules' => ['boolean']],
        ], ['sort' => ['sometimes', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']], nested: 'filter');
        $sort = $filters->parameter('sort');

        $perPage = ListPageSize::resolve($request, 'sites');
        $partial = $request->header('X-Inertia-Partial-Component') === 'sites/index';
        $index = null;
        $readIndex = static function () use ($reader, $user, $filters, $perPage, &$index): array {
            return $index ??= $reader->index($user, $filters, $perPage);
        };
        $page = null;
        $read = static function () use ($reader, $user, $filters, $partial, $readIndex, &$page): LengthAwarePaginator {
            return $page ??= $partial ? $reader->page($user, $filters) : $readIndex()['page'];
        };
        $attention = null;
        $readAttention = static function () use ($reader, $user, $partial, $readIndex, &$attention): array {
            return $attention ??= $partial ? $reader->attention($user) : [
                'rows' => $reader->attentionRowPayloads($readIndex()['attention']),
                'total' => $readIndex()['summary']->attention,
            ];
        };
        $packages = null;
        $readPackages = static function () use ($reader, $user, &$packages): array {
            return $packages ??= $reader->advisoryPackages($user);
        };

        return Inertia::render('sites/index', new SitesIndexPageData(
            sites: Lazy::inertiaDeferred(static fn (): array => $reader->rowPayloads($read()->getCollection(), includeHistory: false), 'list'),
            // A second group so the rows render before the uptime and sparkline history arrives.
            sites_history: Lazy::inertiaDeferred(static fn (): array => $reader->history($read()->getCollection()), 'history'),
            sites_pagination: Lazy::closure(static function () use ($read): PaginationData {
                $page = $read();

                return PaginationData::fromPaginator($page);
            }),
            filters: Lazy::closure(static fn (): array => $filters->data()),
            sort: is_string($sort) ? $sort : 'status',
            clients: Lazy::closure(static fn (): array => $reader->clients($user)),
            tags: Lazy::closure(static fn (): array => $tags->read($user)),
            summary: Lazy::closure(static fn (): SiteListSummaryData => $partial ? $reader->summary($user) : $readIndex()['summary']),
            can_create: Lazy::closure(static fn (): bool => $user->hasAnyRole(['Owner', 'Admin'])),
            tiles: Lazy::closure(static fn (): DashboardTilesData => $summaries->read($user, siteStates: $partial ? null : $readIndex()['states'])[0]),
            needs_attention: Lazy::closure(static fn (): array => $readAttention()['rows']),
            needs_attention_total: Lazy::closure(static fn (): int => $readAttention()['total']),
            advisory_packages: Lazy::closure(static fn (): array => $readPackages()['packages']),
            more_advisory_packages: Lazy::closure(static fn (): bool => $readPackages()['more']),
        ));
    }
}

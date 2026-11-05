<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\CheckKey;
use Heptapod\Data\Ui\DashboardTilesData;
use Heptapod\Data\Ui\PaginationData;
use Heptapod\Data\Ui\SiteListSummaryData;
use Heptapod\Data\Ui\SitesFilterData;
use Heptapod\Data\Ui\SitesIndexPageData;
use Heptapod\Enums\SiteStatus;
use Heptapod\Models\User;
use Heptapod\Queries\DashboardSummaryReader;
use Heptapod\Queries\SitesReader;
use Heptapod\Queries\TagsReader;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class SitesIndexController extends Controller
{
    public function __invoke(Request $request, SitesReader $reader, DashboardSummaryReader $summaries, TagsReader $tags): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasAnyRole(['Owner', 'Admin', 'Member']), 403);
        Validator::make($request->all(), [
            'filter' => ['sometimes', 'array:search,client_id,state,tag,check,attention'],
            'filter.search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter.client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filter.state' => ['sometimes', 'nullable', Rule::enum(SiteStatus::class)],
            'filter.tag' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter.check' => ['sometimes', 'nullable', Rule::enum(CheckKey::class)],
            'filter.attention' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        $partial = $request->header('X-Inertia-Partial-Component') === 'sites/index';
        $index = null;
        $readIndex = static function () use ($reader, $user, $request, &$index): array {
            return $index ??= $reader->index($user, $request);
        };
        $page = null;
        $read = static function () use ($reader, $user, $request, $partial, $readIndex, &$page): LengthAwarePaginator {
            return $page ??= $partial ? $reader->page($user, $request) : $readIndex()['page'];
        };
        $attention = null;
        $readAttention = static function () use ($reader, $user, $partial, $readIndex, &$attention): array {
            return $attention ??= $partial ? $reader->attention($user) : [
                'rows' => $reader->rowPayloads($readIndex()['attention'], includeHistory: false, includePresentations: true),
                'total' => $readIndex()['summary']->attention,
            ];
        };

        return Inertia::render('sites/index', new SitesIndexPageData(
            sites: Lazy::inertiaDeferred(static fn (): array => $reader->rowPayloads($read()->getCollection()), 'list'),
            pagination: Lazy::closure(static function () use ($read): PaginationData {
                $page = $read();

                return new PaginationData($page->currentPage(), $page->perPage(), $page->total(), $page->lastPage(), $page->nextPageUrl(), $page->previousPageUrl());
            }),
            filters: Lazy::closure(static fn (): SitesFilterData => $reader->filters($request)),
            clients: Lazy::closure(static fn (): array => $reader->clients($user)),
            tags: Lazy::closure(static fn (): array => $tags->read($user)),
            summary: Lazy::closure(static fn (): SiteListSummaryData => $partial ? $reader->summary($user) : $readIndex()['summary']),
            can_create: Lazy::closure(static fn (): bool => $user->hasAnyRole(['Owner', 'Admin'])),
            tiles: Lazy::closure(static fn (): DashboardTilesData => $summaries->read($user, siteStates: $partial ? null : $readIndex()['states'])[0]),
            needs_attention: Lazy::closure(static fn (): array => $readAttention()['rows']),
            needs_attention_total: Lazy::closure(static fn (): int => $readAttention()['total']),
        ));
    }
}

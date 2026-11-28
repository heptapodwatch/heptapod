<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\FilterKind;
use Heptapod\Contracts\ListFilters;
use Heptapod\Contracts\PaginationData;
use Heptapod\Data\Ui\ClientsIndexPageData;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\User;
use Heptapod\Queries\ClientsReader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class ClientsIndexController extends Controller
{
    public function __invoke(Request $request, ClientsReader $reader): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasAnyRole(['Owner', 'Admin', 'Member']), 403);
        $filters = ListFilters::read($request, [
            'search' => ['kind' => FilterKind::Search, 'label' => 'Search clients', 'rules' => ['nullable', 'string', 'max:255']],
            'state' => ['kind' => FilterKind::Segmented, 'label' => 'Client state', 'default' => 'all',
                'options' => ['attention' => 'Needs attention', 'all' => 'All clients', 'archived' => null]],
        ], ['page' => ['sometimes', 'integer', 'min:1']], nested: 'filter');

        $perPage = ListPageSize::resolve($request, 'clients');
        $page = null;
        $read = static function () use ($reader, $user, $request, $perPage, &$page): array {
            return $page ??= $reader->index($user, $request, $perPage);
        };

        return Inertia::render('clients/index', new ClientsIndexPageData(
            clients: Lazy::closure(static fn (): array => $read()[0]),
            pagination: Lazy::closure(static fn (): PaginationData => $read()[1]),
            filters: $filters->data(),
            can_manage: $user->hasAnyRole(['Owner', 'Admin']),
        ));
    }
}

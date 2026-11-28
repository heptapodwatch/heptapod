<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\FilterKind;
use Heptapod\Contracts\ListFilters;
use Heptapod\Contracts\PaginationData;
use Heptapod\Data\Ui\ClientsIndexPageData;
use Heptapod\Enums\Capability;
use Heptapod\Enums\SiteStatus;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Heptapod\Queries\ClientsReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;
use Spatie\QueryBuilder\AllowedFilter;

final class ClientsIndexController extends Controller
{
    public function __invoke(Request $request, ClientsReader $reader): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(Capability::ViewInstance->allows($user), 403);
        $filters = ListFilters::read($request, [
            'search' => ['kind' => FilterKind::Search, 'label' => 'Search clients', 'rules' => ['nullable', 'string', 'max:255'],
                'predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value): void {
                    $query->where(static function (Builder $matches) use ($value): void {
                        foreach ((array) $value as $term) {
                            if (! is_string($term)) {
                                throw new \LogicException('Client search must be validated.');
                            }
                            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                            $matches->getQuery()->orWhereRaw(Client::displayNameSql().' ILIKE ?', [__('No client'), $pattern]);
                        }
                    });
                })],
            'state' => ['kind' => FilterKind::Segmented, 'label' => 'Client state', 'default' => 'all',
                'options' => ['attention' => 'Needs attention', 'all' => 'All clients', 'archived' => null],
                'predicate' => static fn (string $key): AllowedFilter => AllowedFilter::callback($key, static function (Builder $query, mixed $value): void {
                    if ($value === 'archived') {
                        $query->getQuery()->whereNotNull('clients.archived_at');
                    } elseif ($value === 'attention') {
                        $query->getQuery()->where('attention.attention_rank', '<=', SiteStatus::quietRank() - 1);
                    } elseif ($value === 'all') {
                        $query->getQuery()->whereNull('clients.archived_at');
                    } else {
                        throw new \LogicException('Client state filter must be validated.');
                    }
                })],
        ], ['page' => ['sometimes', 'integer', 'min:1']], nested: 'filter');

        $page = null;
        $read = static function () use ($reader, $user, $filters, &$page): array {
            return $page ??= $reader->index($user, $filters);
        };

        return Inertia::render('clients/index', new ClientsIndexPageData(
            clients: Lazy::closure(static fn (): array => $read()[0]),
            clients_pagination: Lazy::closure(static fn (): PaginationData => $read()[1]),
            filters: $filters->data(),
            can_manage: Capability::Manage->allows($user),
        ));
    }
}

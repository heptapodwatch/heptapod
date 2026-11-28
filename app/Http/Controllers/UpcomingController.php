<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\FilterKind;
use Heptapod\Contracts\ListFilters;
use Heptapod\Data\Ui\UpcomingIndexPageData;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\User;
use Heptapod\Queries\SitesReader;
use Heptapod\Queries\UpcomingReader;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Inertia\Inertia;
use Inertia\Response;

final class UpcomingController extends Controller
{
    public function __invoke(Request $request, UpcomingReader $reader, SitesReader $sites): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $filters = ListFilters::read($request, [
            'client_id' => ['kind' => FilterKind::Combobox, 'label' => 'Client', 'empty_label' => 'All clients', 'source' => 'clients', 'rules' => ['nullable', 'integer', 'min:1']],
            'type' => ['kind' => FilterKind::Select, 'label' => 'Type', 'default' => 'all', 'options' => ['all' => 'All types', 'certificate' => 'Certificates', 'domain' => 'Domains', 'maintenance' => 'Maintenance', 'task' => 'Tasks']],
            'days' => ['kind' => FilterKind::Segmented, 'label' => 'Time range', 'default' => '90', 'options' => [14 => '14 days', 30 => '30 days', 90 => '90 days', 'all' => 'All']],
        ]);
        $days = $filters->value('days');
        $type = $filters->value('type');
        $clientId = $filters->integer('client_id');
        // A page reads only the dates up to its end (plus one to know of a next page), capped at UpcomingReader::MaxItems
        // so an arbitrary page number cannot force an unbounded read or overflow offset arithmetic.
        $perPage = ListPageSize::resolve($request, 'upcoming');
        $page = Paginator::resolveCurrentPage();
        $maxPage = (int) ceil(UpcomingReader::MaxItems / $perPage);
        $read = $page > $maxPage
            ? []
            : $reader->read($user, $days === 'all' ? 365 : (int) $days, $type === 'all' || $type === null ? null : [$type], $clientId, limit: min($page * $perPage + 1, UpcomingReader::MaxItems));
        $items = new Paginator($read === [] ? [] : array_slice($read, ($page - 1) * $perPage), $perPage, $page, ['path' => Paginator::resolveCurrentPath()]);
        $items->withQueryString();

        return Inertia::render('upcoming/index', new UpcomingIndexPageData(
            items: array_values($items->items()),
            next_page_url: $items->nextPageUrl(),
            previous_page_url: $items->previousPageUrl(),
            per_page: $perPage,
            filters: $filters->data(),
            calendar_now: CarbonImmutable::now('UTC')->toIso8601String(),
            clients: $sites->clients($user),
        ));
    }
}

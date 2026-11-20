<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Data\Ui\UpcomingIndexPageData;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\User;
use Heptapod\Queries\SitesReader;
use Heptapod\Queries\UpcomingReader;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class UpcomingController extends Controller
{
    public function __invoke(Request $request, UpcomingReader $reader, SitesReader $sites): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), ['days' => ['sometimes', Rule::in(['14', '30', '90', 'all'])],
            'type' => ['sometimes', Rule::in(['all', 'certificate', 'domain', 'maintenance', 'task'])],
            'client_id' => ['sometimes', 'nullable', 'integer', 'min:1']])->validate();
        $days = $request->string('days', '90')->toString();
        $type = $request->string('type', 'all')->toString();
        $clientId = $request->filled('client_id') ? $request->integer('client_id') : null;
        // A page reads only the dates up to its end (plus one to know of a next page): a daily window per site would
        // otherwise read every date of the year. So the agenda pages without a total, like Runs history.
        $perPage = ListPageSize::resolve($request, 'upcoming');
        $page = Paginator::resolveCurrentPage();
        $read = $reader->read($user, $days === 'all' ? 365 : (int) $days, $type === 'all' ? null : [$type], $clientId, limit: $page * $perPage + 1);
        $items = new Paginator(array_slice($read, ($page - 1) * $perPage), $perPage, $page, ['path' => Paginator::resolveCurrentPath(), 'query' => $request->query()]);

        return Inertia::render('upcoming/index', new UpcomingIndexPageData(
            items: array_values($items->items()),
            next_page_url: $items->nextPageUrl(),
            previous_page_url: $items->previousPageUrl(),
            per_page: $perPage,
            days: $days,
            type: $type,
            client_id: $clientId,
            calendar_now: CarbonImmutable::now('UTC')->toIso8601String(),
            clients: $sites->clients($user),
        ));
    }
}

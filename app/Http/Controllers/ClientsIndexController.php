<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Data\Ui\ClientsIndexPageData;
use Heptapod\Data\Ui\PaginationData;
use Heptapod\Models\User;
use Heptapod\Queries\ClientsReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;

final class ClientsIndexController extends Controller
{
    public function __invoke(Request $request, ClientsReader $reader): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasAnyRole(['Owner', 'Admin', 'Member']), 403);
        Validator::make($request->all(), ['filter' => ['sometimes', 'array:search,state'], 'filter.search' => ['sometimes', 'nullable', 'string', 'max:255'], 'filter.state' => ['sometimes', 'in:all,attention,archived'], 'page' => ['sometimes', 'integer', 'min:1']])->validate();

        $page = null;
        $read = static function () use ($reader, $user, $request, &$page): ClientsIndexPageData {
            return $page ??= $reader->index($user, $request);
        };

        return Inertia::render('clients/index', new ClientsIndexPageData(
            clients: Lazy::closure(static fn (): ClosureLazy|array => $read()->clients),
            pagination: Lazy::closure(static fn (): ClosureLazy|PaginationData => $read()->pagination),
            search: Lazy::closure(static fn (): ClosureLazy|string|null => $read()->search),
            state: Lazy::closure(static fn (): ClosureLazy|string => $read()->state),
            can_manage: Lazy::closure(static fn (): ClosureLazy|bool => $read()->can_manage),
        ));
    }
}

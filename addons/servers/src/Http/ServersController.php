<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Actions\RemoveServer;
use Heptapod\Addons\Servers\Actions\SnoozeServer;
use Heptapod\Addons\Servers\Actions\UpdateServerSettings;
use Heptapod\Addons\Servers\Data\ServerPageData;
use Heptapod\Addons\Servers\Data\ServerRowData;
use Heptapod\Addons\Servers\Data\ServersPageData;
use Heptapod\Addons\Servers\Data\ServerThresholdsData;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerReader;
use Heptapod\Addons\Servers\ServerState;
use Heptapod\Contracts\FilterKind;
use Heptapod\Contracts\ListFilters;
use Heptapod\Contracts\PaginationData;
use Heptapod\Contracts\ServersAccess;
use Heptapod\Contracts\SiteDirectory;
use Heptapod\Contracts\SnoozeEnd;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class ServersController
{
    public function index(Request $request, ServerReader $reader): Response
    {
        Gate::authorize('viewAny', Server::class);
        $sorts = array_keys(ServerReader::SortKeys);
        $query = ListFilters::read($request, [
            'search' => ['kind' => FilterKind::Search, 'label' => 'servers.list.search', 'rules' => ['nullable', 'string', 'max:255']],
            'state' => ['kind' => FilterKind::Select, 'label' => 'Status', 'empty_label' => 'servers.list.all_states', 'rules' => ['nullable'],
                'options' => [ServerReader::NeedsAttention => 'Needs attention', ServerState::NoReport->value => 'servers.state.no_report', ServerState::Awaiting->value => 'servers.state.awaiting', ServerState::Reporting->value => 'servers.state.reporting']],
        ], ['sort' => ['sometimes', 'string', Rule::in([...$sorts, ...array_map(static fn (string $key): string => '-'.$key, $sorts)])]], nested: 'filter', queryOnly: true);
        $filters = ['search' => $query->value('search'), 'state' => $query->value('state')];
        $requestedSort = $query->parameter('sort');
        $sort = is_string($requestedSort) ? $requestedSort : 'status';
        $page = null;
        $read = static function () use ($reader, $filters, $sort, &$page): array {
            return $page ??= $reader->page(CarbonImmutable::now(), $filters, $sort);
        };

        return Inertia::render('servers/index', new ServersPageData(
            Lazy::closure(static fn (): array => $read()['servers']), Lazy::closure(static fn (): PaginationData => $read()['pagination']),
            ServerThresholdsData::defaults(),
            $query->data(), $sort,
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Server::class);
        /** @var array{name: string} $data */
        $data = Validator::make($request->all(), ['name' => ['required', 'string', 'max:120']])->validate();
        $server = Server::named($data['name']);
        Inertia::flash('server_token', $server->issueToken()->plainText());

        return to_route('servers.show', $server);
    }

    public function show(Request $request, Server $server, ServerReader $reader, ServersAccess $access, SiteDirectory $directory): Response
    {
        Gate::authorize('view', $server);
        $user = $request->user() ?? throw new \LogicException('The server page needs a signed-in user.');
        $now = CarbonImmutable::now();
        $tag = $access->agentReleaseTag();
        $row = null;
        $getRow = static function () use ($reader, $server, $now, &$row): ServerRowData {
            return $row ??= $reader->server($now, $server->id);
        };
        $linked = null;
        $getLinked = static function () use ($reader, $server, $user, $directory, &$linked): array {
            return $linked ??= $directory->page($user, $reader->linkedSiteIds($server->id), 'linked_sites');
        };

        return Inertia::render('servers/show', new ServerPageData(
            Lazy::closure($getRow), ServerThresholdsData::defaults(),
            Lazy::closure(static fn (): array => $reader->history($server->id, $getRow()->ignored_mounts, $now)),
            // The agent's endpoint file holds the full report URL on APP_URL, which the servers must reach.
            ['endpoint' => rtrim(config()->string('app.url'), '/').'/agent/v1/report', 'installed_version' => config()->string('heptapod.version'),
                'version' => $tag, 'release_url' => $tag === null ? null : config()->string('servers.agent_release_url').'/'.$tag],
            Lazy::closure(static fn (): array => $getLinked()['sites']),
            Lazy::closure(static fn (): PaginationData => $getLinked()['pagination']),
        ));
    }

    /** Settings…: the name, the thresholds and the ignored mounts. */
    public function update(Request $request, Server $server, UpdateServerSettings $update): RedirectResponse
    {
        Gate::authorize('update', $server);
        $minutes = ['required', 'integer', 'between:1,1440'];
        $percent = ['required', 'integer', 'between:1,100'];
        $mount = ['string', 'starts_with:/', 'max:256', 'distinct'];
        /** @var array{name: string, mounts: list<array{mount: string, percent: int|numeric-string, minutes: int|numeric-string}>, ignored_mounts: list<string>, memory_percent: int|numeric-string, memory_minutes: int|numeric-string, load_per_core: int|float|numeric-string, load_minutes: int|numeric-string, no_report_minutes: int|numeric-string} $values */
        $values = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'mounts' => ['present', 'array', 'list', 'max:32'], 'mounts.*' => ['array:mount,percent,minutes'], 'mounts.*.mount' => ['required', ...$mount],
            'mounts.*.percent' => $percent, 'mounts.*.minutes' => $minutes,
            'ignored_mounts' => ['present', 'array', 'list', 'max:32'], 'ignored_mounts.*' => $mount,
            'memory_percent' => $percent, 'memory_minutes' => $minutes,
            'load_per_core' => ['required', 'numeric', 'between:0.1,100', 'decimal:0,1'], 'load_minutes' => $minutes,
            'no_report_minutes' => ['required', 'integer', 'between:2,60'],
        ])->validate();
        $update($server, $values);

        return back();
    }

    /** Rotate token: the new token is shown once, and the old one stops working at once. */
    public function rotateToken(Server $server): RedirectResponse
    {
        Gate::authorize('update', $server);
        Inertia::flash('server_token', $server->issueToken()->plainText());

        return to_route('servers.show', $server);
    }

    /** Snooze: the check snooze's choices, a number of minutes or an absolute end, at most a year ahead. */
    public function snooze(Request $request, Server $server, SnoozeServer $snooze): RedirectResponse
    {
        Gate::authorize('update', $server);
        $snooze($server, SnoozeEnd::from($request->all()));

        return back();
    }

    public function unsnooze(Server $server, SnoozeServer $snooze): RedirectResponse
    {
        Gate::authorize('update', $server);
        $snooze($server, null);

        return back();
    }

    public function destroy(Server $server, RemoveServer $remove): RedirectResponse
    {
        Gate::authorize('delete', $server);
        $remove($server);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('servers.removed', ['name' => $server->name])]);

        return to_route('servers.index');
    }
}

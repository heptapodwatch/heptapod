<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Actions\RemoveServer;
use Heptapod\Addons\Servers\Data\ServerPageData;
use Heptapod\Addons\Servers\Data\ServerRowData;
use Heptapod\Addons\Servers\Data\ServersPageData;
use Heptapod\Addons\Servers\Data\ServerThresholdsData;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class ServersController
{
    public function index(ServerReader $reader): Response
    {
        Gate::authorize('viewAny', Server::class);

        return Inertia::render('servers/index', new ServersPageData($reader->rows(CarbonImmutable::now()), ServerThresholdsData::defaults()));
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

    public function show(Server $server, ServerReader $reader): Response
    {
        Gate::authorize('view', $server);
        $now = CarbonImmutable::now();
        $version = config()->string('heptapod.version');

        return Inertia::render('servers/show', new ServerPageData(
            Lazy::closure(static fn (): ServerRowData => $reader->rows($now, $server->id)[0]), ServerThresholdsData::defaults(),
            Lazy::closure(static fn (): array => $reader->history($server->id, $now)),
            ['endpoint' => rtrim(config()->string('app.url'), '/'), 'version' => $version,
                'release_url' => config()->string('servers.agent_release_url').'/'.$version,
                // A source or development build has no agent release of its own.
                'is_release' => preg_match('/^v\d{1,4}\.\d{1,4}\.\d{1,4}$/D', $version) === 1],
        ));
    }

    /** Rotate token: the new token is shown once, and the old one stops working at once. */
    public function rotateToken(Server $server): RedirectResponse
    {
        Gate::authorize('update', $server);
        Inertia::flash('server_token', $server->issueToken()->plainText());

        return to_route('servers.show', $server);
    }

    public function destroy(Server $server, RemoveServer $remove): RedirectResponse
    {
        Gate::authorize('delete', $server);
        $remove($server);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('servers.removed', ['name' => $server->name])]);

        return to_route('servers.index');
    }
}

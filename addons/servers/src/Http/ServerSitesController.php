<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Actions\LinkSites;
use Heptapod\Addons\Servers\Actions\UnlinkSite;
use Heptapod\Addons\Servers\Data\ServerLinkData;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerReader;
use Heptapod\Contracts\SiteAddressLookup;
use Heptapod\Contracts\SiteDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** "Link site…" and Unlink on the server page. */
final class ServerSitesController
{
    /** The sheet shows at most this many DNS suggestions and this many sites per search. */
    public const int ChoiceLimit = 20;

    /**
     * What the "Link site…" sheet offers, read when it opens and on each search: the strongest DNS suggestions (sites on no
     * server yet) and the sites matching the search that are not on this server, with the server linking would move them
     * from. Bounded by ChoiceLimit each, whatever the number of sites.
     */
    public function index(Request $request, Server $server, ServerReader $reader, SiteDirectory $directory): JsonResponse
    {
        Gate::authorize('update', $server);
        $user = $request->user() ?? throw new \LogicException('Linking needs a signed-in user.');
        Validator::make($request->query(), ['q' => ['sometimes', 'nullable', 'string', 'max:255']])->validate();
        $search = trim($request->string('q')->toString());
        /** @var array<int, int> $serverOf site id => server id, every stored link */
        $serverOf = DB::table('server_sites')->pluck('server_id', 'site_id')->all();
        $here = array_keys(array_filter($serverOf, static fn (int $serverId): bool => $serverId === $server->id));
        $suggestions = [];
        if ($search === '' && app()->bound(SiteAddressLookup::class)) {
            $pointing = app(SiteAddressLookup::class)->sitesPointingAt($reader->server(CarbonImmutable::now(), $server->id)->ips,
                array_keys($serverOf));
            // Strongest first, as the lookup ranked them; the sites the user cannot see (archived clients among them) leave
            // before the limit, so they never crowd out one that can be linked.
            $visible = [];
            foreach ($directory->visible($user, array_keys($pointing)) as $site) {
                $visible[$site->id] = $site;
            }
            foreach ($pointing as $siteId => $addresses) {
                if (isset($visible[$siteId]) && count($suggestions) < self::ChoiceLimit) {
                    $suggestions[] = ['site' => $visible[$siteId], 'address' => $addresses[0]];
                }
            }
        }
        $suggested = array_map(static fn (array $suggestion): int => $suggestion['site']->id, $suggestions);
        $sites = $directory->search($user, $search, [...$here, ...$suggested], self::ChoiceLimit);
        /** @var array<int, string> $names */
        $names = DB::table('servers')->pluck('name', 'id')->all();
        $elsewhere = [];
        foreach ($sites as $site) {
            if (isset($serverOf[$site->id])) {
                $elsewhere[] = ['site_id' => $site->id, 'server_name' => $names[$serverOf[$site->id]]];
            }
        }

        return response()->json(new ServerLinkData($suggestions, $sites, $elsewhere)->toArray());
    }

    public function store(Request $request, Server $server, SiteDirectory $directory, LinkSites $link): RedirectResponse
    {
        Gate::authorize('update', $server);
        $user = $request->user() ?? throw new \LogicException('Linking needs a signed-in user.');
        /** @var array{site_ids: list<int>} $data */
        $data = Validator::make($request->all(), ['site_ids' => ['required', 'array', 'list', 'max:500'], 'site_ids.*' => ['integer', 'distinct']])->validate();
        $siteIds = array_map(intval(...), $data['site_ids']);
        if (count($directory->visible($user, $siteIds)) !== count($siteIds)) {
            throw ValidationException::withMessages(['site_ids' => __('servers.link.unknown_site')]);
        }
        $link($server->id, $siteIds);

        return back();
    }

    public function destroy(Server $server, int $site, UnlinkSite $unlink): RedirectResponse
    {
        Gate::authorize('update', $server);
        $unlink($site, $server->id);

        return back();
    }
}

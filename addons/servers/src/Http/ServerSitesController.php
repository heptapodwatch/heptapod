<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Heptapod\Addons\Servers\Actions\LinkSites;
use Heptapod\Addons\Servers\Actions\UnlinkSite;
use Heptapod\Addons\Servers\Server;
use Heptapod\Contracts\SiteDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** "Link site…" and Unlink on the server page. */
final class ServerSitesController
{
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

<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\RequestSiteIconRefresh;
use Heptapod\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class RefreshSiteIconController extends Controller
{
    public function __invoke(Site $site, RequestSiteIconRefresh $refresh): RedirectResponse
    {
        Gate::authorize('update', $site);
        $refresh($site);

        return back();
    }
}

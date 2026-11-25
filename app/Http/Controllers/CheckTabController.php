<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Enums\ResponseTimeRange;
use Heptapod\Models\User;
use Heptapod\Queries\SiteDetailReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CheckTabController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, SiteDetailReader $reader): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $requested = $request->query('response_range', ResponseTimeRange::Hours24->value);
        $range = is_string($requested) ? ResponseTimeRange::tryFrom($requested) : null;
        // An edited or outdated link: open the same check on its default range and say so.
        if ($range === null) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('history.unknown_range')]);

            return to_route('sites.checks.show', ['site' => $site, 'check' => $check]);
        }

        return Inertia::render('sites/show', $reader->checkPage($user, $site, $check, $range));
    }
}

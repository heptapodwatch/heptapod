<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Models\User;
use Heptapod\Queries\SiteDetailReader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CheckTabController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, SiteDetailReader $reader): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('sites/show', $reader->checkPage($user, $site, $check));
    }
}

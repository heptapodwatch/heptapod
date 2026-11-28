<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Models\User;
use Heptapod\Queries\SiteDetailReader;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

final class SiteOverviewController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, int $site, SiteDetailReader $reader): Response
    {
        return Inertia::render('sites/show', $reader->overviewPage($user, $site));
    }
}

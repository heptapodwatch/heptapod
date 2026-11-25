<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Enums\ResponseTimeRange;
use Heptapod\Models\User;
use Heptapod\Queries\SiteDetailReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CheckTabController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, SiteDetailReader $reader): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), ['response_range' => ['sometimes', Rule::enum(ResponseTimeRange::class)]])->validate();
        $range = ResponseTimeRange::from($request->string('response_range', ResponseTimeRange::Hours24->value)->toString());

        return Inertia::render('sites/show', $reader->checkPage($user, $site, $check, $range));
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Live\LiveChannels;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Whether the viewer's live channels carried a change since a page was rendered. A tab asks once its first
 * subscription is ready: later changes reach it as signals, earlier ones only through this answer.
 */
final class LiveChangesController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Request $request, LiveChannels $channels): JsonResponse
    {
        Validator::make($request->all(), ['since' => ['required', 'integer', 'min:0']])->validate();

        return response()->json(['changed' => $channels->changedSince($channels->forUser($user), $request->integer('since'))], 200,
            ['Cache-Control' => 'private, no-store']);
    }
}

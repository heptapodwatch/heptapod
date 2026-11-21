<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\Notifications\UpdateNotificationPreferences;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** PUT /notification-channels/{channel}/preferences: grid cells and site exceptions in one request. */
final class NotificationPreferencesController extends Controller
{
    public function __invoke(Request $request, NotificationDestination $channel, UpdateNotificationPreferences $update): JsonResponse
    {
        Gate::authorize('update', $channel);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array<string, mixed> $input */
        $input = $request->all();
        $update($channel, $input, $actor);

        return response()->json(null, 204);
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\Notifications\UpdateNotificationPreferences;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\NotificationDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** PUT /notification-channels/{channel}/preferences: grid cells, category → immediate | digest | off. */
final class NotificationPreferencesController extends Controller
{
    public function __invoke(Request $request, NotificationDestination $channel, UpdateNotificationPreferences $update): JsonResponse
    {
        Gate::authorize('update', $channel);
        /** @var array<string, mixed> $input */
        $input = $request->all();
        $update($channel, $input);

        return response()->json(null, 204);
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\SetNotificationDestinationEnabled;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\NotificationDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** PATCH /notification-channels/{channel}/enabled: pause discards queued automatic alerts; resume restores nothing. */
final class NotificationChannelEnabledController extends Controller
{
    public function __invoke(Request $request, NotificationDestination $channel, SetNotificationDestinationEnabled $setEnabled): JsonResponse
    {
        Gate::authorize('update', $channel);
        Validator::make($request->all(), ['enabled' => ['required', 'boolean']])->validate();
        $discarded = $setEnabled($channel, $request->boolean('enabled'));

        return response()->json(['id' => $channel->id, 'enabled' => $channel->enabled, 'suppressed_count' => $discarded]);
    }
}

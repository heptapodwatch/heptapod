<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\Notifications\SetNotificationSiteMute;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** PUT|DELETE /notification-channels/{channel}/site-mutes/{site}: "not this site" for one channel. */
final class NotificationSiteMutesController extends Controller
{
    public function update(Request $request, NotificationDestination $channel, Site $site, SetNotificationSiteMute $mute): JsonResponse
    {
        return $this->set($request, $channel, $site, $mute, true);
    }

    public function destroy(Request $request, NotificationDestination $channel, Site $site, SetNotificationSiteMute $mute): JsonResponse
    {
        return $this->set($request, $channel, $site, $mute, false);
    }

    private function set(Request $request, NotificationDestination $channel, Site $site, SetNotificationSiteMute $mute, bool $muted): JsonResponse
    {
        Gate::authorize('update', $channel);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('view', $site);
        $mute($channel, $site, $muted, $actor);

        return response()->json(null, 204);
    }
}

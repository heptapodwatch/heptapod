<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\Notifications\SetNotificationSiteMute;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** PUT|DELETE /notification-channels/{channel}/site-mutes/{site}: "not this site" for one channel. */
final class NotificationSiteMutesController extends Controller
{
    public function update(#[CurrentUser] User $actor, NotificationDestination $channel, Site $site, SetNotificationSiteMute $mute, AuthorizeSiteWrite $authorize): JsonResponse
    {
        return $this->set($actor, $channel, $site, $mute, $authorize, true);
    }

    public function destroy(#[CurrentUser] User $actor, NotificationDestination $channel, Site $site, SetNotificationSiteMute $mute, AuthorizeSiteWrite $authorize): JsonResponse
    {
        return $this->set($actor, $channel, $site, $mute, $authorize, false);
    }

    private function set(User $actor, NotificationDestination $channel, Site $site, SetNotificationSiteMute $mute, AuthorizeSiteWrite $authorize, bool $muted): JsonResponse
    {
        Gate::authorize('update', $channel);
        Gate::forUser($actor)->authorize('view', $site);
        // Decided again under the access-structure lock (the site) and the channel lock (SetNotificationSiteMute).
        $authorize($actor, 'view', static fn (User $current) => $mute($channel, $site, $muted, $current), $site);

        return response()->json(null, 204);
    }
}

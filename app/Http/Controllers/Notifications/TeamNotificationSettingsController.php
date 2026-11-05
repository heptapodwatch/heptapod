<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Data\Ui\NotificationSettingsPageData;
use Heptapod\Data\Ui\TeamNotificationsPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Policies\NotificationDestinationPolicy;
use Heptapod\Queries\NotificationSettingsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** RecipientSettings for the team (design §7.1): Owners and Admins. */
final class TeamNotificationSettingsController extends Controller
{
    public function edit(Request $request, NotificationSettingsReader $reader, NotificationDestinationPolicy $policy): Response
    {
        return Inertia::render('settings/team-notifications', new TeamNotificationsPageData(
            settings: $this->data($request, $reader, $policy),
        ));
    }

    public function show(Request $request, NotificationSettingsReader $reader, NotificationDestinationPolicy $policy): JsonResponse
    {
        return response()->json($this->data($request, $reader, $policy));
    }

    private function data(Request $request, NotificationSettingsReader $reader, NotificationDestinationPolicy $policy): NotificationSettingsPageData
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->manageTeam($user)->authorize();

        return $reader->team($user);
    }
}

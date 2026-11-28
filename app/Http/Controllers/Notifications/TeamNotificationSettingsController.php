<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Data\Ui\TeamNotificationsPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Queries\NotificationSettingsReader;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/** RecipientSettings for the team (design §7.1): Owners and Admins. */
final class TeamNotificationSettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user, NotificationSettingsReader $reader): Response
    {
        return Inertia::render('settings/team-notifications', new TeamNotificationsPageData(
            settings: $reader->team($user),
        ));
    }

    public function show(#[CurrentUser] User $user, NotificationSettingsReader $reader): JsonResponse
    {
        return response()->json($reader->team($user));
    }
}

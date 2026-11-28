<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Data\Ui\OwnNotificationsPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Queries\NotificationSettingsReader;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/** RecipientSettings for the signed-in user (design §7.1). */
final class OwnNotificationSettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user, NotificationSettingsReader $reader): Response
    {
        return Inertia::render('settings/notifications', new OwnNotificationsPageData(
            settings: $reader->user($user),
        ));
    }

    public function show(#[CurrentUser] User $user, NotificationSettingsReader $reader): JsonResponse
    {
        return response()->json($reader->user($user));
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Data\Ui\OwnNotificationsPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Queries\NotificationSettingsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** RecipientSettings for the signed-in user (design §7.1). */
final class OwnNotificationSettingsController extends Controller
{
    public function edit(Request $request, NotificationSettingsReader $reader): Response
    {
        return Inertia::render('settings/notifications', new OwnNotificationsPageData(
            settings: $reader->user($this->user($request)),
        ));
    }

    public function show(Request $request, NotificationSettingsReader $reader): JsonResponse
    {
        return response()->json($reader->user($this->user($request)));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\ClientNotificationsPageData;
use Heptapod\Data\Ui\NotificationSettingsPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Heptapod\Policies\NotificationDestinationPolicy;
use Heptapod\Queries\NotificationSettingsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** RecipientSettings for one client (design §7.1): ClientPolicy::update. */
final class ClientNotificationSettingsController extends Controller
{
    public function edit(Request $request, Client $client, NotificationSettingsReader $reader, NotificationDestinationPolicy $policy): Response
    {
        return Inertia::render('clients/notifications', new ClientNotificationsPageData(
            settings: $this->data($request, $client, $reader, $policy),
            client: ClientData::fromClient($client),
        ));
    }

    public function show(Request $request, Client $client, NotificationSettingsReader $reader, NotificationDestinationPolicy $policy): JsonResponse
    {
        return response()->json($this->data($request, $client, $reader, $policy));
    }

    private function data(Request $request, Client $client, NotificationSettingsReader $reader, NotificationDestinationPolicy $policy): NotificationSettingsPageData
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->manageClient($user, $client->id)->authorize();

        return $reader->client($user, $client);
    }
}

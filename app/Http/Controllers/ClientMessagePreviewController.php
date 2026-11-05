<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\SendClientMessage;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ClientMessagePreviewController extends Controller
{
    /** POST …/client-messages/preview: the message as it would be sent, for Owners and Admins with access to the client. */
    public function __invoke(Request $request, Client $client, SendClientMessage $send): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('view', $client);
        abort_unless(Gate::forUser($user)->allows('update', $client), 403);
        /** @var array<string, mixed> $input */
        $input = $request->all();

        return response()->json($send->preview($user, $client, $input))->header('Cache-Control', 'private, no-store');
    }
}

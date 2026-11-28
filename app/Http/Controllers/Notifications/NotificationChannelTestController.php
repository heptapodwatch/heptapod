<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\Notifications\SaveNotificationChannel;
use Heptapod\Actions\SendTestNotification;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** POST /notification-channels/{channel}/test: a synchronous test send, rate limited per actor and channel. */
final class NotificationChannelTestController extends Controller
{
    public function __invoke(Request $request, NotificationDestination $channel, SendTestNotification $sendTest, SaveNotificationChannel $save): JsonResponse
    {
        Gate::authorize('sendTest', $channel);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        /** @var array{draft?: array<string, mixed>} $input */
        $input = Validator::make($request->all(), ['draft' => ['sometimes', 'array']])->validate();
        $draft = null;
        if (isset($input['draft'])) {
            Gate::authorize('update', $channel);
            $draft = $save->draft($channel, $input['draft']);
        }

        return response()->json($sendTest->execute($channel, $actor, $draft));
    }
}

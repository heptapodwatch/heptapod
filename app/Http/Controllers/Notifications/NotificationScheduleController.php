<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\Notifications\UpdateNotificationSchedule;
use Heptapod\Enums\RecipientKind;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Policies\NotificationDestinationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PATCH /notification-schedules/{kind}/{id?}: quiet hours and digest slot. The user kind is always the signed-in user. */
final class NotificationScheduleController extends Controller
{
    public function __invoke(Request $request, string $kind, UpdateNotificationSchedule $update, NotificationDestinationPolicy $policy, ?int $id = null): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $recipient = RecipientKind::tryFrom($kind) ?? abort(404);
        abort_if($recipient === RecipientKind::Team && $id !== null, 404);
        match ($recipient) {
            RecipientKind::Team => $policy->manageTeam($user)->authorize(),
            RecipientKind::User => $policy->manageUser($user, $id ?? $user->id)->authorize(),
            RecipientKind::Client => $policy->manageClient($user, $id ?? 0)->authorize(),
        };
        /** @var array<string, mixed> $input */
        $input = $request->all();
        $schedule = $update($recipient, $recipient === RecipientKind::User ? $user->id : null, $recipient === RecipientKind::Client ? $id : null, $input);

        return response()->json(['quiet_start' => $schedule->quiet_start, 'quiet_end' => $schedule->quiet_end, 'quiet_weekends' => $schedule->quiet_weekends,
            'digest_time' => $schedule->digest_time, 'digest_weekdays' => $schedule->digest_weekdays]);
    }
}

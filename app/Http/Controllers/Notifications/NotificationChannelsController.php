<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\ArchiveNotificationChannel;
use Heptapod\Actions\Notifications\SaveNotificationChannel;
use Heptapod\Enums\RecipientKind;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Policies\NotificationDestinationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Add, edit and remove channels of RecipientSettings (design §7.1). Every write goes through NotificationDestinationPolicy. */
final class NotificationChannelsController extends Controller
{
    public function store(Request $request, SaveNotificationChannel $save, NotificationDestinationPolicy $policy): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array{recipient_kind: string, client_id?: int} $recipient */
        $recipient = Validator::make($request->all(), [
            'recipient_kind' => ['required', Rule::enum(RecipientKind::class)],
            'client_id' => ['required_if:recipient_kind,client', 'prohibited_unless:recipient_kind,client', 'integer'],
        ])->validate();
        $kind = RecipientKind::from($recipient['recipient_kind']);
        $client = null;
        match ($kind) {
            RecipientKind::Team => $policy->manageTeam($actor)->authorize(),
            RecipientKind::User => null,
            RecipientKind::Client => $policy->manageClient($actor, $recipient['client_id'] ?? 0)->authorize(),
        };
        if ($kind === RecipientKind::Client) {
            $client = Client::query()->findOrFail($recipient['client_id'] ?? 0);
        }
        /** @var array<string, mixed> $attributes */
        $attributes = $request->except(['recipient_kind', 'client_id']);
        $channel = $save->add($kind, $kind === RecipientKind::User ? $actor : null, $client, $attributes);

        return response()->json(['id' => $channel->id], 201);
    }

    public function update(Request $request, NotificationDestination $channel, SaveNotificationChannel $save): JsonResponse
    {
        Gate::authorize('update', $channel);
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $save->edit($channel, $attributes);

        return response()->json(['id' => $channel->id]);
    }

    /** Remove archives: history stays and queued work is suppressed. The account channel cannot be removed. */
    public function destroy(Request $request, NotificationDestination $channel, ArchiveNotificationChannel $archive): JsonResponse
    {
        Gate::authorize('update', $channel);
        abort_if($channel->account_mail, 422, __('notifications.account_channel_kept'));
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $suppressed = $archive($channel, ArchiveNotificationChannel::Archived, $actor);

        return response()->json(['id' => $channel->id, 'suppressed_count' => $suppressed]);
    }
}

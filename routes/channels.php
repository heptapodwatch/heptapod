<?php

declare(strict_types=1);

use Heptapod\Enums\Capability;
use Heptapod\Models\User;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Broadcast;

// Route scanners also resolve controllers; only an authorization request may build the broadcaster.
app()->resolving(BroadcastController::class, static function (): void {
    $action = ltrim(request()->route()?->getActionName() ?? '', '\\');
    if ($action !== BroadcastController::class.'@authenticate') {
        return;
    }

    $connection = config('broadcasting.default');
    abort_if($connection === null || $connection === 'null', 503, __('Live updates are not configured.'));

    if ($connection === 'reverb') {
        foreach (['key', 'secret', 'app_id'] as $field) {
            $credential = config('broadcasting.connections.reverb.'.$field);
            abort_unless(is_string($credential) && $credential !== '', 503, __('Live updates are not configured.'));
        }
    }

    Broadcast::channel('instance', static fn (User $user): object|bool => request()->input('channel_name') === 'presence-instance'
        && Capability::ViewInstance->allows($user) && ! $user->isRestrictedMember() ? (object) [] : false);
    Broadcast::channel('client.{clientId}', static fn (User $user, int $clientId): object|bool => request()->input('channel_name') === 'presence-client.'.$clientId
        && Capability::ViewClient->allows($user, $clientId) ? (object) [] : false);
});

<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Notification;

final class PushoverChannel extends TransportChannel
{
    public function key(): string
    {
        return 'pushover';
    }

    public function nameKey(): string
    {
        return 'notifications.channel.pushover';
    }

    public function descriptionKey(): string
    {
        return 'notifications.channel.pushover.description';
    }

    public function icon(): string
    {
        return 'bell';
    }

    public function configSchema(): Schema
    {
        return new Schema(['user_key' => $this->textField('channels.field.user_key', true, ['size:30']), 'token' => $this->textField('channels.field.token', true)]);
    }

    protected function deliver(RenderedAlert $alert, DestinationConfig $destination, string $idempotencyKey): DeliveryResult
    {
        $notifiable = Notification::route('pushover', $destination->string('user_key'));
        $response = $this->http('https://api.pushover.net/1/messages.json')->asMultipart()
            ->post('https://api.pushover.net/1/messages.json', [
                'token' => $destination->string('token'), 'user' => $notifiable->routeNotificationFor('pushover'),
                'title' => $alert->title(limit: RenderedAlert::PUSHOVER_LIMITS['title']), 'message' => $alert->details(limit: RenderedAlert::PUSHOVER_LIMITS['body']),
                ...($alert->message->link === null ? [] : ['url' => $alert->message->link, 'url_title' => $alert->translate('notifications.view')]),
                'html' => '0', 'monospace' => '0',
            ])->throw();
        if (! $response->successful() || $response->json('status') !== 1) {
            return $this->failure($destination, $response);
        }
        $id = $response->json('request');

        return new DeliveryResult(DeliveryState::Sent, providerMessageId: is_string($id) ? $id : null);
    }
}

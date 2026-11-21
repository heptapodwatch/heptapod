<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Notification;

final class NtfyChannel extends TransportChannel
{
    public function key(): string
    {
        return 'ntfy';
    }

    public function nameKey(): string
    {
        return 'notifications.channel.ntfy';
    }

    public function descriptionKey(): string
    {
        return 'notifications.channel.ntfy.description';
    }

    public function icon(): string
    {
        return 'bell-ring';
    }

    public function configSchema(): Schema
    {
        return new Schema([
            'server' => $this->textField('channels.field.server', rules: ['bail', 'url:http,https', 'delivery_https']),
            'topic' => $this->textField('channels.field.topic', true, ['regex:/^[a-zA-Z0-9_-]{1,64}$/']),
            'token' => $this->headerSecretField('channels.field.token', false),
        ]);
    }

    protected function deliver(RenderedAlert $alert, DestinationConfig $destination, string $idempotencyKey): DeliveryResult
    {
        $notifiable = Notification::route('ntfy', $destination->string('server'));
        $request = $this->http($destination->string('server'))->asJson();
        $token = $destination->values()['token'] ?? null;
        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }
        /** @var string $recipient */
        $recipient = $notifiable->routeNotificationFor('ntfy');
        $response = $request->post($recipient, [
            'topic' => $destination->string('topic'), 'title' => $alert->title(limit: RenderedAlert::NTFY_LIMIT),
            'message' => $alert->details(limit: RenderedAlert::NTFY_LIMIT),
            ...($alert->message->link === null ? [] : ['click' => $alert->message->link]),
        ])->throw();
        $id = $response->json('id');
        // Only ntfy's published-message JSON for this topic proves delivery; a proxy or captive portal also answers 2xx.
        if (! $response->successful() || ! is_string($id) || $id === '' || $response->json('event') !== 'message'
            || $response->json('topic') !== $destination->string('topic')) {
            return $this->failure($destination, $response);
        }

        return new DeliveryResult(DeliveryState::Sent, providerMessageId: $id);
    }
}

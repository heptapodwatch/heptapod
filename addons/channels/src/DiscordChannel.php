<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Notification;

final class DiscordChannel extends TransportChannel
{
    public function key(): string
    {
        return 'discord';
    }

    public function nameKey(): string
    {
        return 'notifications.channel.discord';
    }

    public function descriptionKey(): string
    {
        return 'notifications.channel.discord.description';
    }

    public function icon(): string
    {
        return 'message-circle';
    }

    public function configSchema(): Schema
    {
        return new Schema(['channel' => $this->textField('channels.field.channel'), 'token' => $this->headerSecretField('channels.field.token')]);
    }

    public function supportsIdempotencyKey(): bool
    {
        return true;
    }

    protected function deliver(RenderedAlert $alert, DestinationConfig $destination, string $idempotencyKey): DeliveryResult
    {
        $notifiable = Notification::route('discord', $destination->string('channel'));
        /** @var string $recipient */
        $recipient = $notifiable->routeNotificationFor('discord');
        $response = $this->http('https://discord.com/api/channels/'.$recipient.'/messages')->asJson()
            ->withToken($destination->string('token'), 'Bot')->post('https://discord.com/api/channels/'.$recipient.'/messages', [
                'content' => '',
                'embeds' => [[
                    'title' => $alert->title(true, RenderedAlert::DISCORD_LIMITS['title']), 'description' => $alert->details(true, RenderedAlert::DISCORD_LIMITS['body']),
                    ...($alert->message->link === null ? [] : ['url' => $alert->message->link]),
                    'timestamp' => $alert->message->since->toIso8601String(),
                ]],
                'nonce' => substr(hash('sha256', $idempotencyKey), 0, 24), 'enforce_nonce' => true,
                'allowed_mentions' => ['parse' => []],
            ])->throw();
        if (! $response->successful() || $response->json('code') !== null) {
            return $this->failure($destination, $response);
        }
        $id = $response->json('id');

        return new DeliveryResult(DeliveryState::Sent, providerMessageId: is_string($id) ? $id : null);
    }
}

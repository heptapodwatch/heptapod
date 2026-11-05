<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Telegram\Telegram;

final class TelegramChannel extends TransportChannel
{
    public function key(): string
    {
        return 'telegram';
    }

    public function nameKey(): string
    {
        return 'notifications.channel.telegram';
    }

    public function descriptionKey(): string
    {
        return 'notifications.channel.telegram.description';
    }

    public function icon(): string
    {
        return 'send';
    }

    public function configSchema(): Schema
    {
        return new Schema(['chat_id' => $this->textField('channels.field.chat_id'), 'token' => $this->textField('channels.field.token', true)]);
    }

    protected function deliver(RenderedAlert $alert, DestinationConfig $destination, string $idempotencyKey): DeliveryResult
    {
        $notifiable = Notification::route('telegram', $destination->string('chat_id'));
        /** @var string $recipient */
        $recipient = $notifiable->routeNotificationFor('telegram');
        $message = $alert->toTelegram()->to($recipient);
        $response = new Response(new Telegram($destination->string('token'), $this->httpClient('https://api.telegram.org'))->sendMessage($message->toArray()))->throw();
        if (! $response->successful() || $response->json('ok') !== true) {
            return $this->failure($destination, $response);
        }
        $result = $response->json('result');
        $id = is_array($result) ? ($result['message_id'] ?? null) : null;

        return new DeliveryResult(DeliveryState::Sent, providerMessageId: is_int($id) ? (string) $id : null);
    }
}

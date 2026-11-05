<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Notifications\Slack\SlackRoute;
use Illuminate\Support\Facades\Notification;

final class SlackChannel extends TransportChannel
{
    public function key(): string
    {
        return 'slack';
    }

    public function nameKey(): string
    {
        return 'notifications.channel.slack';
    }

    public function descriptionKey(): string
    {
        return 'notifications.channel.slack.description';
    }

    public function icon(): string
    {
        return 'hash';
    }

    public function configSchema(): Schema
    {
        return new Schema(['channel' => $this->textField('channels.field.channel'), 'token' => $this->headerSecretField('channels.field.token')]);
    }

    protected function deliver(RenderedAlert $alert, DestinationConfig $destination, string $idempotencyKey): DeliveryResult
    {
        $route = SlackRoute::make($destination->string('channel'), $destination->string('token'));
        $notifiable = Notification::route('slack', $route);
        /** @var SlackRoute $recipient */
        $recipient = $notifiable->routeNotificationFor('slack');
        /** @var array<string, mixed> $payload */
        $payload = $alert->toSlack()->toArray();
        $payload['channel'] = $recipient->channel;
        $payload['parse'] = 'none';
        // The package sender has no per-send redirect control; retain its route and Block Kit builder.
        $response = $this->http('https://slack.com/api/chat.postMessage')->asJson()
            ->withToken($destination->string('token'))->post('https://slack.com/api/chat.postMessage', $payload)->throw();
        if (! $response->successful() || $response->json('ok') !== true) {
            return $this->failure($destination, $response);
        }
        $id = $response->json('ts');

        return new DeliveryResult(DeliveryState::Sent, providerMessageId: is_string($id) ? $id : null);
    }
}

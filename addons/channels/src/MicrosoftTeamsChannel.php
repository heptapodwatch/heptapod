<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Notification;

final class MicrosoftTeamsChannel extends TransportChannel
{
    public function key(): string
    {
        return 'microsoftTeams';
    }

    public function nameKey(): string
    {
        return 'notifications.channel.microsoftTeams';
    }

    public function descriptionKey(): string
    {
        return 'notifications.channel.microsoftTeams.description';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function configSchema(): Schema
    {
        return new Schema(['url' => $this->textField('channels.field.url', true, ['url:http,https'])]);
    }

    protected function deliver(RenderedAlert $alert, DestinationConfig $destination, string $idempotencyKey): DeliveryResult
    {
        $notifiable = Notification::route('microsoftTeams', $destination->string('url'));
        /** @var string $recipient */
        $recipient = $notifiable->routeNotificationFor('microsoftTeams');
        $payload = [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard', 'version' => '1.5',
                    'body' => [
                        ['type' => 'TextBlock', 'wrap' => true, 'style' => 'heading', 'text' => $alert->title(true, RenderedAlert::TEAMS_LIMITS['title']), 'weight' => 'bolder', 'size' => 'large'],
                        ['type' => 'TextBlock', 'wrap' => true, 'text' => $alert->details(true, RenderedAlert::TEAMS_LIMITS['body'])],
                    ],
                    'actions' => $alert->message->link === null ? [] : [['type' => 'Action.OpenUrl', 'title' => $alert->translate('notifications.view'), 'url' => $alert->message->link]],
                ],
            ]],
        ];
        // Keep UTF-8 as UTF-8: JSON \u escapes would expand bounded emoji text
        // beyond the connector payload budget even though its content fits.
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($body) > RenderedAlert::TEAMS_LIMITS['payload']) {
            throw new \LengthException('Teams notification exceeds the serialized payload budget.');
        }
        $response = $this->http($recipient)
            ->withBody($body, 'application/json')->post($recipient)->throw();
        // Workflows accepts with an empty body; any other body signals failure even on HTTP 200.
        if (! $response->successful() || $response->body() !== '') {
            return $this->failure($destination, $response);
        }

        return new DeliveryResult(DeliveryState::Sent);
    }
}

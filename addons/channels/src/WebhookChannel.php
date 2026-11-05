<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Notification;

final class WebhookChannel extends TransportChannel
{
    public function key(): string
    {
        return 'webhook';
    }

    public function nameKey(): string
    {
        return 'notifications.channel.webhook';
    }

    public function descriptionKey(): string
    {
        return 'notifications.channel.webhook.description';
    }

    public function icon(): string
    {
        return 'webhook';
    }

    public function configSchema(): Schema
    {
        return new Schema([
            'url' => $this->textField('channels.field.url', true, ['url:http,https', 'webhook_scheme']),
            'secret' => $this->textField('channels.field.secret', true),
            'allow_insecure_http' => new Field(FieldType::Boolean, ['sometimes'], presentation: new FieldPresentation('channels.field.allow_insecure_http')),
        ]);
    }

    public function supportsIdempotencyKey(): bool
    {
        return true;
    }

    protected function deliver(RenderedAlert $alert, DestinationConfig $destination, string $idempotencyKey): DeliveryResult
    {
        $notifiable = Notification::route('webhook', $destination->string('url'));
        /** @var string $url */
        $url = $notifiable->routeNotificationFor('webhook');
        // A system issue is about the instance: same keys, site and check fields null (docs/webhook-receivers.md).
        $system = $alert->message->systemFacts !== null;
        $payload = [
            'event_id' => $alert->message->eventId, 'destination_id' => $destination->destinationId,
            'severity' => $alert->message->cause === 'errored' ? 'error' : $alert->message->severity->value,
            'cause' => $alert->message->cause, 'title' => $alert->title(), 'summary' => $alert->details(),
            'headline' => $alert->headline(), 'changes' => $alert->changes(), 'details' => $alert->presentationDetails(),
            'site_id' => $alert->message->siteId, 'site_label' => $system ? null : $alert->siteLabel(),
            'site_url' => $system ? null : $alert->siteUrl(), 'check_type' => $alert->message->checkType,
            'also_affected' => $alert->message->affectedChecks,
            'rechecking' => $alert->message->recheckingChecks,
            'since' => $alert->message->since->toIso8601String(), 'downtime_seconds' => $alert->message->downtimeSeconds,
            'link' => $alert->message->link, 'is_test' => $alert->message->isTest,
        ];
        $signer = new TimestampSigner(now()->getTimestamp());
        // Match Spatie's exact JSON bytes. Retries and queuing belong to the notification delivery outbox, not WebhookCallJob.
        $response = $this->http($url)->withHeaders([
            'X-Heptapod-Timestamp' => (string) $signer->timestamp,
            $signer->signatureHeaderName() => $signer->calculateSignature($url, $payload, $destination->string('secret')),
            'X-Heptapod-Delivery' => $alert->message->eventId.':'.$destination->destinationId,
            'Idempotency-Key' => $idempotencyKey,
        ])->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')->post($url)->throw();

        return $response->successful()
            ? new DeliveryResult(DeliveryState::Sent)
            : $this->failure($destination, $response);
    }
}

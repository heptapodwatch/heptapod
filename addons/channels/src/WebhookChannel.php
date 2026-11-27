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
    private const array ReservedHeaders = [
        'content-type', 'content-length', 'host', 'user-agent', 'connection', 'keep-alive', 'transfer-encoding',
        'te', 'trailer', 'upgrade', 'expect', 'proxy-authorization', 'proxy-connection',
        'idempotency-key', 'x-heptapod-timestamp', 'x-heptapod-delivery', TimestampSigner::HeaderName,
    ];

    public static function allowsHeaderName(mixed $name): bool
    {
        return is_string($name) && ! in_array(strtolower($name), self::ReservedHeaders, true);
    }

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
            'headers' => new Field(FieldType::List, ['sometimes', 'max:5'], items: new Field(FieldType::Object, properties: [
                'name' => new Field(FieldType::String, ['bail', 'required', 'max:255', 'regex:/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', 'webhook_header_name', 'distinct:ignore_case'],
                    presentation: new FieldPresentation('channels.field.header_name')),
                'value' => $this->headerSecretField('channels.field.header_value', rules: ['max:4096', 'not_in:********']),
            ]), presentation: new FieldPresentation('channels.field.headers')),
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
        // A system or server issue has no site: same keys, site and check fields null; a server issue adds its server
        // (docs/webhook-receivers.md).
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
            'repositories' => $alert->message->repositories,
            ...($alert->message->server === null ? [] : ['server' => $alert->message->server]),
        ];
        $signer = new TimestampSigner(now()->getTimestamp());
        /** @var list<array{name: string, value: string}> $headers */
        $headers = $destination->values()['headers'] ?? [];
        // Match Spatie's exact JSON bytes. Retries and queuing belong to the notification delivery outbox, not WebhookCallJob.
        $response = $this->http($url)->withHeaders([
            ...array_column($headers, 'value', 'name'),
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

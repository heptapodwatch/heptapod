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

abstract class IncomingWebhookChannel extends TransportChannel
{
    protected const string ProviderUrlRule = '';

    final public function nameKey(): string
    {
        return 'notifications.channel.'.$this->key();
    }

    final public function descriptionKey(): string
    {
        return $this->nameKey().'.description';
    }

    final public function configSchema(): Schema
    {
        return new Schema(['url' => new Field(FieldType::Secret, ['bail', 'required', 'url:https', 'max:2048', ...(
            static::ProviderUrlRule === '' ? [] : ['webhook_provider_url:'.static::ProviderUrlRule]
        )], presentation: new FieldPresentation('channels.field.webhook_url'), targetUrl: true)]);
    }

    /** @return array<string, mixed> */
    abstract protected function payload(RenderedAlert $alert): array;

    final protected function deliver(RenderedAlert $alert, DestinationConfig $destination, string $idempotencyKey): DeliveryResult
    {
        $url = $destination->string('url');
        $response = $this->http($url)->asJson()->post($url, $this->payload($alert))->throw();

        return $response->successful() ? new DeliveryResult(DeliveryState::Sent) : $this->failure($destination, $response);
    }
}

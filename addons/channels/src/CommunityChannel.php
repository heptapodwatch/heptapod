<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelType;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Throwable;

/**
 * Adapts a community Laravel notification channel (any object with send($notifiable, $notification)). Its outcome is
 * opaque, so any return counts as sent and any Throwable as failed; only the exception class is recorded, never its
 * message, which may contain credentials. The vendor channel reads its route from the configured field.
 */
final readonly class CommunityChannel implements ChannelType
{
    public function __construct(
        private string $key,
        private string $nameKey,
        private string $descriptionKey,
        private string $icon,
        private Schema $configSchema,
        private object $vendorChannel,
        private string $routeField,
    ) {
        if (! is_callable([$vendorChannel, 'send'])) {
            throw new \InvalidArgumentException('A community channel must implement send($notifiable, $notification).');
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function nameKey(): string
    {
        return $this->nameKey;
    }

    public function descriptionKey(): string
    {
        return $this->descriptionKey;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function configSchema(): Schema
    {
        return $this->configSchema;
    }

    public function supportsIdempotencyKey(): bool
    {
        return false;
    }

    public function send(ChannelRecipient $recipient, Notification $notification): DeliveryResult
    {
        $destination = $recipient->destination;
        $this->configSchema->validateConfig($destination->values());
        $notifiable = new AnonymousNotifiable()->route($this->key, $destination->string($this->routeField));
        try {
            $send = [$this->vendorChannel, 'send'];
            if (! is_callable($send)) {
                throw new \LogicException('A community channel must implement send($notifiable, $notification).');
            }
            $send($notifiable, $notification);
        } catch (Throwable $exception) {
            $class = new ReflectionClass($exception)->getShortName();
            Log::warning('Notification transport failed.', ['destination_id' => $destination->destinationId, 'channel' => $this->key, 'exception_class' => $class]);

            return new DeliveryResult(DeliveryState::Failed, 'notifications.transport_failed', diagnosis: $class);
        }

        return new DeliveryResult(DeliveryState::Sent);
    }
}

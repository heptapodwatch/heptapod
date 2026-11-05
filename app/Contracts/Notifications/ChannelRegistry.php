<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Traits\Localizable;

/** Installed channels are container-tagged with ChannelType::class by their addon provider. */
final readonly class ChannelRegistry
{
    use Localizable;

    /** Our drivers live beside Laravel's own: account mails (reset, invitation, verification) keep the framework mail driver. */
    public const string DriverPrefix = 'heptapod.';

    public function __construct(private Container $container) {}

    public function get(string $key): ChannelType
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException('Notification channel is not installed: '.$key);
    }

    /** @return array<string, ChannelType> */
    public function all(): array
    {
        $channels = [];
        foreach ($this->container->tagged(ChannelType::class) as $channel) {
            if (! $channel instanceof ChannelType) {
                throw new \LogicException('Invalid tagged notification channel.');
            }
            $key = $channel->key();
            if (isset($channels[$key])) {
                throw new \LogicException('Duplicate notification channel: '.$key);
            }
            $channels[$key] = $channel;
        }

        return $channels;
    }

    public static function registerDrivers(ChannelManager $manager, Container $container): void
    {
        foreach (new self($container)->all() as $key => $channel) {
            $manager->extend(self::DriverPrefix.$key, fn (): ChannelType => $channel);
        }
    }

    /**
     * Send one notification synchronously through the Laravel driver and return its exact outcome. The Laravel
     * NotificationSender returns nothing and may veto, so the ledger calls the driver directly.
     */
    public function send(string $key, ChannelRecipient $recipient, Notification $notification): DeliveryResult
    {
        $driver = $this->container->make(ChannelManager::class)->driver(self::DriverPrefix.$key);
        if (! $driver instanceof ChannelType) {
            throw new \LogicException('Notification driver is not a Heptapod channel: '.$key);
        }

        return $this->withLocale($recipient->preferredLocale(), static fn (): DeliveryResult => $driver->send($recipient, $notification));
    }
}

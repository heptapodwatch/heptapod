<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\RoutesNotifications;

/**
 * The Notifiable a channel driver sends to. Automatic and test sends build it from the channel row (an account
 * channel's address is the current users.email); manual sends build it only from deliveries.recipient_snapshot,
 * so a later channel edit cannot redirect an approved message.
 */
final readonly class ChannelRecipient implements HasLocalePreference
{
    use RoutesNotifications;

    public function __construct(public DestinationConfig $destination, private ?string $mailAddress) {}

    public static function forChannel(DestinationConfig $destination): self
    {
        $address = $destination->values()['email'] ?? null;

        return new self($destination, is_string($address) && $address !== '' ? $address : null);
    }

    /** @param array{address: string, name: string, locale: string} $snapshot */
    public static function fromSnapshot(DestinationConfig $destination, array $snapshot): self
    {
        return new self($destination->withLocale($snapshot['locale']), $snapshot['address']);
    }

    public function preferredLocale(): string
    {
        return $this->destination->locale;
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->mailAddress;
    }
}

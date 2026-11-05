<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Heptapod\Contracts\Schema\Schema;
use Illuminate\Notifications\Notification;

/** An installed channel. ChannelRegistry also registers it as the Laravel notification driver "heptapod.<key>". */
interface ChannelType
{
    public function key(): string;

    public function nameKey(): string;

    /** Addon-owned picker description; translations in both supported languages are required. */
    public function descriptionKey(): string;

    public function icon(): string;

    public function configSchema(): Schema;

    public function send(ChannelRecipient $recipient, Notification $notification): DeliveryResult;

    public function supportsIdempotencyKey(): bool;
}

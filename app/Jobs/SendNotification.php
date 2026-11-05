<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Heptapod\Actions\SendNotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;

#[Timeout(self::TimeoutSeconds)]
final class SendNotification implements ShouldQueue
{
    use Queueable;

    public const int TimeoutSeconds = 60;

    /** A claim's lease outlives the worker timeout by 30 s of acknowledgement and reclaim grace. */
    public const int LeaseSeconds = self::TimeoutSeconds + 30;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function handle(SendNotificationDelivery $send): void
    {
        $send($this->deliveryId);
    }
}

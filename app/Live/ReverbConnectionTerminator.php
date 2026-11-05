<?php

declare(strict_types=1);

namespace Heptapod\Live;

use Heptapod\Contracts\LiveConnectionTerminator;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;

final readonly class ReverbConnectionTerminator implements LiveConnectionTerminator
{
    public function __construct(private BroadcastManager $broadcasts) {}

    public function terminate(int $userId): void
    {
        if (config('broadcasting.default') !== 'reverb') {
            return;
        }
        $broadcaster = $this->broadcasts->connection('reverb');
        if (! $broadcaster instanceof PusherBroadcaster) {
            throw new \UnexpectedValueException('Reverb must use the Pusher broadcaster.');
        }
        $broadcaster->getPusher()->terminateUserConnections((string) $userId);
    }
}

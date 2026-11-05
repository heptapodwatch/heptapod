<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Heptapod\Events\LiveSignal;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Http\Client\NetworkExceptionInterface;
use Pusher\ApiErrorException;

#[Tries(1)]
final class BroadcastLiveSignal implements ShouldQueue
{
    use Queueable;

    private const int MAX_CHANNELS_PER_TRIGGER = 100;

    public function __construct(public LiveSignal $signal)
    {
        $this->onConnection('redis-broadcasts');
        $this->onQueue('broadcasts');
    }

    public function handle(BroadcastManager $broadcasts): void
    {
        foreach (['key', 'secret', 'app_id'] as $field) {
            $credential = config('broadcasting.connections.reverb.'.$field);
            if (! is_string($credential) || $credential === '') {
                RateLimiter::attempt('live:reverb:configuration', 1, static function (): void {
                    report(new \RuntimeException('Reverb app credentials are missing; configure REVERB_APP_ID, REVERB_APP_KEY and REVERB_APP_SECRET.'));
                }, 60);

                return;
            }
        }

        $broadcaster = $broadcasts->connection('reverb');
        if (! $broadcaster instanceof PusherBroadcaster) {
            throw new \UnexpectedValueException('Reverb must use the Pusher broadcaster.');
        }
        $channels = array_map(static fn (PresenceChannel $channel): string => $channel->name, $this->signal->broadcastOn());
        // Pusher rejects more than 100 channels per trigger. Chunks carry the same payload, so the logical event is unchanged.
        foreach (array_chunk($channels, self::MAX_CHANNELS_PER_TRIGGER) as $chunk) {
            try {
                $broadcaster->getPusher()->trigger($chunk, $this->signal->broadcastAs(), $this->signal->broadcastWith());
            } catch (ApiErrorException $exception) {
                if ($exception->getCode() !== 0 || ! $exception->getPrevious() instanceof NetworkExceptionInterface) {
                    throw $exception;
                }
                report($exception);
            }
        }
    }
}

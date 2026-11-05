<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Heptapod\Contracts\LiveConnectionTerminator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Tries(3)]
final class TerminateLiveConnections implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
        $this->onConnection('redis-broadcasts');
        $this->onQueue('broadcasts');
    }

    public function handle(LiveConnectionTerminator $terminator): void
    {
        try {
            $terminator->terminate($this->userId);
        } catch (Throwable $exception) {
            Cache::store('redis')->forever('heptapod:live:termination-failed-at', now('UTC')->toIso8601String());
            report($exception);

            throw $exception;
        }
    }
}

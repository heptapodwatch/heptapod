<?php

declare(strict_types=1);

namespace Heptapod\Checks\Execution;

use InvalidArgumentException;

final class ExecutionBudget
{
    public const int QueueWait = 60;

    public const int QueuedStaleTimeout = 600;

    public const int HeavyQueuedStaleTimeout = 1500;

    public const int Grace = 30;

    public const int MaximumTimeout = 1200;

    /** Worker bookkeeping must not consume the addon's network/execution deadline. */
    public const int BookkeepingGrace = 15;

    public const int HorizonTimeout = self::MaximumTimeout + 2 * self::BookkeepingGrace;

    public const int RetryAfter = self::HorizonTimeout + self::Grace;

    public const int StopGrace = self::HorizonTimeout + self::BookkeepingGrace;

    public static function queuedLease(int $timeout, string $queue): int
    {
        self::lease($timeout);

        return self::queuedStaleTimeout($queue);
    }

    public static function queuedStaleTimeout(string $queue): int
    {
        return match ($queue) {
            'checks-fast' => self::QueuedStaleTimeout,
            'checks-crawl', 'checks-browser' => self::HeavyQueuedStaleTimeout,
            default => throw new InvalidArgumentException('Unknown check queue.'),
        };
    }

    public static function runningLease(int $timeout): int
    {
        return self::lease($timeout) - self::QueueWait;
    }

    public static function lease(int $timeout): int
    {
        if ($timeout < 1 || $timeout > self::MaximumTimeout) {
            throw new InvalidArgumentException('Addon timeout must be between 1 and 1200 seconds.');
        }

        return self::QueueWait + $timeout + self::Grace;
    }
}

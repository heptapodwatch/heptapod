<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * The framework rate limiter in one Redis round trip per operation, on the same keys and values.
 *
 * The framework increment() takes four separate commands and loses concurrent hits, and its throttle checks before
 * it hits, so a parallel burst passes the check together. Here increment() and consume() are single Redis scripts.
 * Other cache stores keep the framework implementation.
 */
final class AtomicRateLimiter extends RateLimiter
{
    private const string Increment = <<<'LUA'
        redis.call('SET', KEYS[2], ARGV[2], 'EX', ARGV[1], 'NX')
        redis.call('SET', KEYS[1], 0, 'EX', ARGV[1], 'NX')
        return redis.call('INCRBY', KEYS[1], ARGV[3])
        LUA;

    /** KEYS: counter and timer per limit; ARGV: maxAttempts, decay seconds and timer value per limit. */
    private const string Consume = <<<'LUA'
        for limit = 1, #KEYS / 2 do
            local counter, timer = KEYS[limit * 2 - 1], KEYS[limit * 2]
            if tonumber(redis.call('GET', counter) or '0') >= tonumber(ARGV[limit * 3 - 2]) then
                if redis.call('EXISTS', timer) == 1 then
                    return limit
                end
                redis.call('DEL', counter)
            end
        end
        for limit = 1, #KEYS / 2 do
            local counter, timer, seconds = KEYS[limit * 2 - 1], KEYS[limit * 2], ARGV[limit * 3 - 1]
            redis.call('SET', timer, ARGV[limit * 3], 'EX', seconds, 'NX')
            redis.call('SET', counter, 0, 'EX', seconds, 'NX')
            redis.call('INCRBY', counter, 1)
        end
        return 0
        LUA;

    /**
     * @param  string  $key
     * @param  \DateTimeInterface|\DateInterval|int  $decaySeconds
     * @param  int  $amount
     */
    public function increment($key, $decaySeconds = 60, $amount = 1): int
    {
        $store = $this->cache->getStore();
        if (! $store instanceof RedisStore) {
            return parent::increment($key, $decaySeconds, $amount);
        }
        $counter = $store->getPrefix().$this->cleanRateLimiterKey($key);
        $seconds = max(1, $this->secondsUntil($decaySeconds));

        return $this->evaluate($store, self::Increment, [$counter, $counter.':timer'], [$seconds, $this->availableAt($seconds), $amount]);
    }

    /**
     * Checks every limit and, only when none is exhausted, hits each of them, as one step.
     *
     * @param  list<array{key: string, maxAttempts: int, decaySeconds: int}>  $limits
     * @return int|null the index of the first exhausted limit; null when the hits were recorded
     */
    public function consume(array $limits): ?int
    {
        $store = $this->cache->getStore();
        if (! $store instanceof RedisStore) {
            foreach ($limits as $index => $limit) {
                if ($this->tooManyAttempts($limit['key'], $limit['maxAttempts'])) {
                    return $index;
                }
            }
            foreach ($limits as $limit) {
                $this->hit($limit['key'], $limit['decaySeconds']);
            }

            return null;
        }
        $keys = [];
        $arguments = [];
        foreach ($limits as $limit) {
            $counter = $store->getPrefix().$this->cleanRateLimiterKey($limit['key']);
            $seconds = max(1, $limit['decaySeconds']);
            $keys[] = $counter;
            $keys[] = $counter.':timer';
            $arguments[] = $limit['maxAttempts'];
            $arguments[] = $seconds;
            $arguments[] = $this->availableAt($seconds);
        }
        $exhausted = $this->evaluate($store, self::Consume, $keys, $arguments);

        return $exhausted === 0 ? null : $exhausted - 1;
    }

    /**
     * The scripts write plain integers, which is what the framework stores when the connection neither serializes nor compresses.
     *
     * @param  list<string>  $keys
     * @param  list<int>  $arguments
     */
    private function evaluate(RedisStore $store, string $script, array $keys, array $arguments): int
    {
        $connection = $store->connection();
        if (! $connection instanceof PhpRedisConnection || $connection->serialized() || $connection->compressed()) {
            throw new \LogicException('The atomic rate limiter requires a phpredis cache connection without serialization or compression.');
        }
        $result = $connection->eval($script, count($keys), ...$keys, ...$arguments);
        if (! is_int($result)) {
            throw new \UnexpectedValueException('The rate limiter script must return an integer.');
        }

        return $result;
    }
}

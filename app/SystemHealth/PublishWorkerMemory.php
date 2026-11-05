<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Illuminate\Support\Facades\Cache;
use UnexpectedValueException;

final class PublishWorkerMemory
{
    public const string Key = 'heptapod:health:worker-memory';

    public function __invoke(): void
    {
        $cache = Cache::store('redis');
        if (! $cache->add(self::Key.':throttle', true, 60)) {
            return;
        }
        $path = config()->string('heptapod.health.worker_cgroup_path');
        if (! is_file($path.'/memory.stat') || ! is_file($path.'/memory.max')) {
            $cache->put(self::Key, ['available' => false, 'reason' => 'no_sample'], 180);

            return;
        }
        $statistics = file_get_contents($path.'/memory.stat');
        $maximum = file_get_contents($path.'/memory.max');
        if ($statistics === false || $maximum === false) {
            throw new UnexpectedValueException('Worker cgroup memory counters could not be read.');
        }
        if (trim($maximum) === 'max') {
            $cache->put(self::Key, ['available' => false, 'reason' => 'unlimited'], 180);

            return;
        }
        preg_match_all('/^([a-z_]+) ([0-9]+)$/m', $statistics, $matches);
        $counters = array_map(
            static fn (string $value): int|false => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]),
            array_combine($matches[1], $matches[2]),
        );
        if (! isset($counters['anon'])) {
            throw new UnexpectedValueException('Worker cgroup memory.stat must contain the anon counter.');
        }
        $anon = $counters['anon'];
        $kernel = $counters['kernel'] ?? $this->kernelFromParts($counters);
        $maximum = filter_var(trim($maximum), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($anon === false || $kernel === false || $maximum === false || $anon > PHP_INT_MAX - $kernel) {
            throw new UnexpectedValueException('Worker cgroup memory counters must be valid integers.');
        }
        $cache->put(self::Key, ['current' => $anon + $kernel, 'max' => $maximum], 180);
    }

    /**
     * Kernels before 5.18 have no aggregate "kernel" counter; approximate it from its main parts (a lower bound).
     *
     * @param  array<string, int|false>  $counters
     */
    private function kernelFromParts(array $counters): int|false
    {
        if (! isset($counters['kernel_stack'], $counters['slab'])) {
            throw new UnexpectedValueException('Worker cgroup memory.stat must contain kernel, or kernel_stack and slab counters.');
        }
        $kernel = 0;
        foreach (['kernel_stack', 'slab', 'pagetables', 'percpu', 'vmalloc'] as $part) {
            $value = $counters[$part] ?? 0;
            if ($value === false || $kernel > PHP_INT_MAX - $value) {
                return false;
            }
            $kernel += $value;
        }

        return $kernel;
    }
}

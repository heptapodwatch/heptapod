<?php

declare(strict_types=1);

namespace Heptapod\Checks\Execution;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Memory budget of the shared Horizon container for its fixed process topology.
 *
 * Measured on the production image (2026-10-20): an idle Horizon cgroup charges 782 MiB
 * for 14 PHP processes; fast, browser and delivery jobs add at most 20 MiB each above idle. Crawl memory grows with
 * the collected URL count (712 MiB RSS for 2,000 pages and 17,561 URLs), so crawl workers get their own PHP limit:
 * a larger crawl fails inside PHP with a recorded cause instead of a kernel kill of the whole container.
 */
final class WorkerMemoryBudget
{
    public const int IdleProcessMiB = 56;

    public const int ActiveJobsMiB = 64;

    /** Crawl worker PHP memory_limit (MiB) of the default profile (hosts with 6 GiB or more); HORIZON_MEMORY_LIMIT 1990m. */
    public const int DefaultCrawlPhpLimitMiB = 768;

    /** Crawl worker PHP memory_limit (MiB) of the small profile (hosts below 6 GiB); HORIZON_MEMORY_LIMIT 1670m. */
    public const int SmallCrawlPhpLimitMiB = 512;

    /** The setting operators raise when a crawl stops at its PHP limit. */
    public const string CrawlLimitSetting = 'HEPTAPOD_CRAWL_MEMORY_LIMIT_MB';

    /** Released after a memory_limit fatal so the worker can still record the errored run. */
    public const int RecordingReserveMiB = 32;

    public const int HeadroomPercent = 25;

    /**
     * Supervisors Horizon deploys for this environment: the first matching environment plan merged over the defaults,
     * as Horizon's ProvisioningPlan does.
     *
     * @param  array<string, array<string, mixed>>  $defaults  horizon.defaults
     * @param  array<string, array<string, array<string, mixed>>>  $environments  horizon.environments
     * @return array<string, array<string, mixed>>
     */
    public static function effectiveSupervisors(array $defaults, array $environments, string $environment): array
    {
        foreach ($environments as $pattern => $plan) {
            if (Str::is($pattern, $environment)) {
                // Horizon merges the whole defaults map, so a supervisor defined only in the defaults also runs.
                /** @var array<string, array<string, mixed>> $supervisors */
                $supervisors = array_replace_recursive($defaults, $plan);

                return $supervisors;
            }
        }

        throw new InvalidArgumentException("Horizon has no supervisor plan for environment {$environment}.");
    }

    /**
     * Smallest container cap with the headroom over the modeled peak: every process idle, the non-crawl jobs active,
     * and every crawl worker at its PHP limit (heptapod.worker_memory.crawl_php_limit_mib) plus the recording reserve. Extra processes are other long-running
     * processes in the same container (the all-in-one role's supervisord, dispatcher and scheduler).
     *
     * @param  array<string, array<string, mixed>>  $supervisors  effectiveSupervisors()
     */
    public static function requiredMiB(array $supervisors, int $crawlPhpLimitMiB, int $extraProcesses = 0): int
    {
        $processes = 1 + $extraProcesses;
        $crawlWorkers = 0;
        foreach ($supervisors as $name => $options) {
            $workers = $options['maxProcesses'] ?? throw new InvalidArgumentException("Horizon supervisor {$name} has no maxProcesses.");
            if (! is_int($workers) || $workers < 1) {
                throw new InvalidArgumentException("Horizon supervisor {$name} must have a positive maxProcesses.");
            }
            $processes += 1 + $workers;
            if (($options['queue'] ?? null) === ['checks-crawl']) {
                $crawlWorkers += $workers;
            }
        }
        $peak = $processes * self::IdleProcessMiB + self::ActiveJobsMiB
            + $crawlWorkers * ($crawlPhpLimitMiB + self::RecordingReserveMiB - self::IdleProcessMiB);

        return intdiv($peak * (100 + self::HeadroomPercent) + 99, 100);
    }

    /** @param  array<string, array<string, mixed>>  $supervisors  effectiveSupervisors() */
    public static function crawlWorkers(array $supervisors): int
    {
        $workers = 0;
        foreach ($supervisors as $options) {
            if (($options['queue'] ?? null) === ['checks-crawl'] && is_int($options['maxProcesses'] ?? null)) {
                $workers += $options['maxProcesses'];
            }
        }

        return $workers;
    }

    /** PHP shorthand bytes (memory_limit) in MiB; -1 means unlimited and is not a valid worker limit. */
    public static function phpLimitMiB(string $value): int
    {
        if (preg_match('/^(\d+)([KMG]?)$/i', trim($value), $match) !== 1) {
            throw new InvalidArgumentException("PHP memory_limit {$value} is not a fixed size.");
        }

        return match (strtoupper($match[2])) {
            'G' => (int) $match[1] * 1024,
            'M' => (int) $match[1],
            'K' => intdiv((int) $match[1], 1024),
            default => intdiv((int) $match[1], 1024 * 1024),
        };
    }
}

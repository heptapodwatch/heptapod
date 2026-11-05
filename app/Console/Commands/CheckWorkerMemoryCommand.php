<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Checks\Execution\WorkerMemoryBudget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Refuse a Horizon worker container whose memory cap is below the budget of its worker topology')]
#[Signature('heptapod:worker-memory {--extra-processes=0 : Other long-running processes sharing this container}')]
final class CheckWorkerMemoryCommand extends Command
{
    public function handle(): int
    {
        /** @var array<string, array<string, mixed>> $defaults */
        $defaults = config()->array('horizon.defaults');
        /** @var array<string, array<string, array<string, mixed>>> $environments */
        $environments = config()->array('horizon.environments');
        $environment = config('horizon.env');
        $supervisors = WorkerMemoryBudget::effectiveSupervisors($defaults, $environments, is_string($environment) ? $environment : config()->string('app.env'));
        $extra = filter_var($this->option('extra-processes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($extra === false) {
            throw new \InvalidArgumentException('--extra-processes must be a nonnegative integer.');
        }
        $crawlLimit = config()->integer('heptapod.worker_memory.crawl_php_limit_mib');
        $required = WorkerMemoryBudget::requiredMiB($supervisors, $crawlLimit, $extra);
        $path = config()->string('heptapod.health.worker_cgroup_path').'/memory.max';
        $limit = is_file($path) ? file_get_contents($path) : false;
        if ($limit === false) {
            $this->error("Cannot read the worker memory cap from {$path}.");

            return self::FAILURE;
        }
        $limit = trim($limit);
        if ($limit === 'max') {
            $this->warn("Worker memory is not capped; the budget for this topology is {$required} MiB.");

            return self::SUCCESS;
        }
        $capMiB = intdiv((int) $limit, 1024 * 1024);
        $crawlWorkers = WorkerMemoryBudget::crawlWorkers($supervisors);
        if ($capMiB < $required) {
            $this->error("HORIZON_MEMORY_LIMIT is {$capMiB}m; HEPTAPOD_CRAWL_CONCURRENCY={$crawlWorkers} with HEPTAPOD_CRAWL_MEMORY_LIMIT_MB={$crawlLimit} needs at least {$required}m. Set HORIZON_MEMORY_LIMIT={$required}m or more in .env.prod.");

            return self::FAILURE;
        }
        $this->info("Worker memory cap {$capMiB} MiB covers the {$required} MiB budget (HEPTAPOD_CRAWL_CONCURRENCY={$crawlWorkers}, HEPTAPOD_CRAWL_MEMORY_LIMIT_MB={$crawlLimit}).");

        return self::SUCCESS;
    }
}

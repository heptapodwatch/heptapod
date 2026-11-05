<?php

declare(strict_types=1);

use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\Supervisor;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['queue.default' => 'redis', 'heptapod.browser.enabled' => true]);

if ($argv[1] === 'cleanup') {
    foreach (['checks-browser', 'checks-crawl', 'broadcasts'] as $queue) {
        Queue::connection($queue === 'broadcasts' ? 'redis-broadcasts' : 'redis')->clear($queue);
    }
    exit(0);
}

$checks = array_map(intval(...), array_slice($argv, 2));
$supervisors = [];
foreach (ProvisioningPlan::get('capacity')->parsed['*'] as $options) {
    $queues = explode(',', $options->queue);
    if (array_intersect($queues, ['checks-browser', 'checks-crawl']) === []) {
        continue;
    }
    $options->sleep = 0.1;
    $supervisor = new Supervisor($options);
    $count = $argv[1] === 'parallel' && in_array('checks-browser', $queues, true) ? 2 : $options->maxProcesses;
    $supervisor->scale($count);
    $supervisors[] = $supervisor;
}
$memoryPeak = 0;
$workingMemoryPeak = 0;
$stop = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$stop): void {
    $stop = true;
});
try {
    foreach ($supervisors as $supervisor) {
        $supervisor->processPools->each->monitor();
        echo json_encode(['queues' => $supervisor->options->queue, 'workers' => $supervisor->totalProcessCount()], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    foreach ($checks as $id) {
        if (app(ClaimService::class)->runNow($id, RunOrigin::System) === null) {
            throw new RuntimeException('Fixture check could not be claimed.');
        }
    }
    $until = microtime(true) + 140;
    while (! $stop && DB::table('check_runs')->whereIn('check_id', $checks)->count() < count($checks)) {
        if (microtime(true) >= $until) {
            throw new RuntimeException('Capacity fixture exceeded its worker budget.');
        }
        foreach ($supervisors as $supervisor) {
            $supervisor->processPools->each->monitor();
        }
        if ($argv[1] === 'combined') {
            $current = file_get_contents('/sys/fs/cgroup/memory.current');
            $stat = file_get_contents('/sys/fs/cgroup/memory.stat');
            if (! is_string($current) || ! ctype_digit(trim($current)) || ! is_string($stat) || preg_match('/^anon (\d+)$/m', $stat, $anon) !== 1 || preg_match('/^kernel (\d+)$/m', $stat, $kernel) !== 1) {
                throw new RuntimeException('Combined capacity measurement requires cgroup v2 memory telemetry.');
            }
            $memoryPeak = max($memoryPeak, (int) $current);
            $workingMemoryPeak = max($workingMemoryPeak, (int) $anon[1] + (int) $kernel[1]);
        }
        usleep(10000);
    }
} finally {
    // Horizon sends SIGTERM to workers and lets in-flight jobs finish.
    foreach ($supervisors as $supervisor) {
        $supervisor->processPools->each(function ($pool): void {
            $pool->processes()->each->terminate();
        });
    }
    foreach ($supervisors as $supervisor) {
        while ($supervisor->processPools->map->runningProcesses()->collapse()->isNotEmpty()) {
            usleep(10000);
        }
    }
}
echo 'workers-stopped'.PHP_EOL;
if ($argv[1] === 'combined') {
    echo json_encode(['app_cgroup_peak_bytes' => $memoryPeak, 'app_cgroup_anon_kernel_peak_bytes' => $workingMemoryPeak,
        'app_cgroup_limit' => trim(file_get_contents('/sys/fs/cgroup/memory.max')),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
}

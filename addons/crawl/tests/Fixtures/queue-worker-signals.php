<?php

declare(strict_types=1);

use Heptapod\Actions\RequestCheckRun;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Models\Check;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['queue.default' => 'redis']);

if ($argv[1] === 'cleanup') {
    foreach (['checks-crawl', 'checks-fast', 'notifications', 'broadcasts'] as $queue) {
        Queue::connection($queue === 'broadcasts' ? 'redis-broadcasts' : 'redis')->clear($queue);
    }
    exit(0);
}

$checks = array_map(intval(...), array_slice($argv, 2));
foreach ($checks as $id) {
    app(RequestCheckRun::class)(Check::query()->findOrFail($id), RunOrigin::System);
}
if ($argv[1] === 'after-crawl') {
    $processed = 0;
    Event::listen(JobProcessed::class, function () use (&$processed): void {
        $processed++;
    });
    Event::listen(JobProcessing::class, function () use (&$processed): void {
        if ($processed === 1) {
            echo "next-running\n";
            flush();
        }
    });
}
app('queue.worker')->daemon('redis', 'checks-crawl,checks-fast', new WorkerOptions(timeout: ExecutionBudget::HorizonTimeout, sleep: 0, maxTries: 1));
echo json_encode([
    'runs' => DB::table('check_runs')->whereIn('check_id', $checks)->orderBy('check_id')->pluck('result')->all(),
    'attempts' => DB::table('check_run_attempts')->whereIn('check_id', $checks)->orderBy('check_id')->get(['phase', 'check_run_id'])->all(),
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'reserved' => app('redis')->zcard('queues:checks-crawl:reserved') + app('redis')->zcard('queues:checks-fast:reserved'),
], JSON_THROW_ON_ERROR).PHP_EOL;

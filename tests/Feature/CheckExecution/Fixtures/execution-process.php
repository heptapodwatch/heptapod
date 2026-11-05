<?php

declare(strict_types=1);

use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Jobs\RunCheck;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1];
if ($mode === 'dispatch-hold') {
    DB::transaction(function () use ($argv): void {
        app(ClaimService::class)->dispatchDue(1);
        file_put_contents($argv[2], 'locked');
        while (! file_exists($argv[3])) {
            usleep(10000);
        }
    });
} elseif ($mode === 'dispatch-kill-before-enqueue') {
    // Redis's queue boundary fires after the claim commit and before writing the job.
    Event::listen(JobQueueing::class, function (JobQueueing $event): void {
        if ($event->job instanceof RunCheck) {
            posix_kill(getmypid(), SIGKILL);
        }
    });
    app(ClaimService::class)->dispatchDue(1);
} elseif ($mode === 'execute' || $mode === 'execute-hold') {
    app()->instance(OutboundHttp::class, new FakeOutboundHttp);
    app(OutboundHttp::class)->respondUsing(function () use ($mode, $argv): FetchResult {
        if ($mode === 'execute-hold') {
            file_put_contents($argv[4], 'executing');
            while (true) {
                usleep(10000);
            }
        }

        return new FetchResult(200, 'ok');
    });
    $job = new RunCheck((int) $argv[2], $argv[3], 60, 'checks-fast');
    app()->call($job->handle(...));
} else {
    exit($app->make(Kernel::class)->call('heptapod:dispatch', ['--once' => true]));
}

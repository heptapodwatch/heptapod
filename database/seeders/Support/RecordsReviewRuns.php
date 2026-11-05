<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Checks\History\DatabaseHistoryReader;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Check;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Fixture ownership setup only; real check execution and recorder own every result and transition. */
trait RecordsReviewRuns
{
    private function evaluate(Check $check, CarbonImmutable $at, ?CheckOutcome $outcome = null, ?DatabaseHistoryReader $history = null): void
    {
        CarbonImmutable::withTestNow($at, function () use ($check, $at, $outcome, $history): void {
            $context = ClaimedRun::start($check, $at, history: $history);
            $result = $outcome ?? app(CheckRegistry::class)->get($check->type)->execute($context);
            if (! app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $at, $at->addSecond(), $result))->accepted) {
                throw new \LogicException('Review run was rejected.');
            }
        });
    }

    private function ping(CronCheck $task, CarbonImmutable $at, string $phase = 'finished'): void
    {
        CarbonImmutable::withTestNow($at, function () use ($task, $phase): void {
            $request = Request::create(route('cron.ping', ['uuid' => $task->uuid, 'phase' => $phase], false), 'POST');
            $kernel = app(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            if ($response->getStatusCode() !== 204) {
                throw new \LogicException('Review cron ping failed: '.$response->getContent());
            }
        });
    }
}

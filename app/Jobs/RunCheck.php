<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\Execution\WorkerMemoryBudget;
use Heptapod\Checks\Execution\WorkerMemoryExhausted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Throwable;

#[FailOnTimeout]
#[Tries(1)]
final class RunCheck implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor;

    public int $timeout;

    /** The job this worker process is executing, kept for the shutdown handler; a fatal error skips `finally`. */
    private static ?self $executing = null;

    private static bool $shutdownHandlerRegistered = false;

    public function __construct(public int $checkId, public string $claimToken, public int $executionTimeout, string $queue)
    {
        $this->uniqueFor = ExecutionBudget::lease($executionTimeout);
        $this->timeout = $executionTimeout + ExecutionBudget::BookkeepingGrace;
        $this->onConnection('redis');
        $this->onQueue($queue);
    }

    /** Attempt-scoped locks allow lease replacements while an old queued job still exists. */
    public function uniqueId(): string
    {
        return $this->checkId.':'.$this->claimToken;
    }

    public function handle(CheckExecutor $executor): void
    {
        if (! self::$shutdownHandlerRegistered) {
            register_shutdown_function($this->failOnMemoryExhaustion(...));
            self::$shutdownHandlerRegistered = true;
        }
        // The crawl worker's own PHP limit bounds one crawl inside the container budget (WorkerMemoryBudget).
        if ($this->queue === 'checks-crawl' && ini_set('memory_limit', config()->integer('heptapod.worker_memory.crawl_php_limit_mib').'M') === false) {
            throw new \LogicException('Crawl worker memory_limit could not be set.');
        }
        self::$executing = $this;
        try {
            $executor->execute($this->checkId, $this->claimToken, $this->executionTimeout);
        } finally {
            self::$executing = null;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof Throwable) {
            app(CheckExecutor::class)->failed($this->checkId, $this->claimToken, $exception, $this->timeout);
        }
    }

    /**
     * A PHP memory_limit fatal cannot be caught. Without this, the dead attempt keeps its lease until replacement and the
     * reserved queue job later fails as MaxAttemptsExceeded with no result. Failing the job here deletes the reservation
     * and records an errored run with the cause through failed().
     */
    private function failOnMemoryExhaustion(): void
    {
        $job = self::$executing;
        $error = error_get_last();
        if (! $job instanceof self || $error === null || $error['type'] !== E_ERROR || ! str_starts_with($error['message'], 'Allowed memory size of')) {
            return;
        }
        // The exhausted heap is still allocated. Recording needs this reserve, which WorkerMemoryBudget counts.
        ini_set('memory_limit', (WorkerMemoryBudget::phpLimitMiB(ini_get('memory_limit')) + WorkerMemoryBudget::RecordingReserveMiB).'M');
        // The fatal may have interrupted a transaction (for example the result recorder); its writes must not commit.
        DB::rollBack(0);
        $job->fail(new WorkerMemoryExhausted('Check worker ran out of memory.'));
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Checks\Execution;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckPending;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Checks\UptimeDependency;
use Heptapod\Contracts\AvailabilityFailure;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\EngineMessage;
use Heptapod\Contracts\GuardsAcceptance;
use Heptapod\Contracts\HistoryReader;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\PreviousResult;
use Heptapod\Contracts\RunCancelled;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\SecretMinimum;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Contracts\StaleEvaluation;
use Heptapod\Contracts\TargetAvailabilityException;
use Heptapod\Contracts\TlsFailureReason;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\AlertConfirmation;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class CheckExecutor
{
    public function __construct(private CheckRegistry $registry, private HistoryReader $history, private ResultRecorder $recorder, private DatabaseClock $clock) {}

    public function execute(int $checkId, string $claimToken, int $timeout): void
    {
        $check = Check::query()->find($checkId);
        if (! $check instanceof Check) {
            return;
        }
        $type = $this->registry->get($check->type);
        if ($type instanceof GuardsAcceptance && $type->requiresSerialization($checkId)) {
            // One stale attempt switches the next bounded DB-only evaluation to a locked read through acceptance. This is the
            // outermost transaction, so it owns the recorder's deadlock retry (Laravel retries only at the top level): a 40P01
            // rolls the whole evaluation back and runs it once more, still fenced by the claim token.
            DB::transaction(function () use ($checkId, $claimToken, $timeout): void {
                $query = Check::query()->whereKey($checkId);
                $query->getQuery()->lockForUpdate();
                $query->firstOrFail();
                $this->executeAttempt($checkId, $claimToken, $timeout);
            }, attempts: 2);

            return;
        }
        $this->executeAttempt($checkId, $claimToken, $timeout);
    }

    private function executeAttempt(int $checkId, string $claimToken, int $timeout): void
    {
        $context = $this->start($checkId, $claimToken, $timeout);
        if (! $context instanceof CheckContext) {
            return;
        }
        $check = Check::query()->find($checkId);
        if (! $check instanceof Check) {
            return;
        }
        $type = $this->registry->get($check->type);
        if ($type instanceof GuardsAcceptance) {
            $context = $context->withAcceptanceRevision($type->revision($checkId));
        }
        $control = new AttemptControl($checkId, $claimToken);
        $context = $context->withRunControl($control, $control);
        $schema = $type->definition()->configSchema;
        /** @var array<string, mixed> $secrets */
        $secrets = (array) new Field(FieldType::Object, properties: $schema->fields)->split($context->config)[1];
        $short = array_filter(Arr::flatten([$secrets, array_column($context->crawlerHeaders, 'value')]), SecretMinimum::tooShort(...));
        $configValidated = false;
        try {
            if ($short !== []) {
                throw ValidationException::withMessages(['secrets' => __('checks.secret_too_short')]);
            }
            $schema->validateConfig($context->config);
            $configValidated = true;
            $control->assertActive();
            // Push addons evaluate deadlines here; derived addons read history instead of polling.
            $outcome = $type->execute($context);
        } catch (RunCancelled|StaleEvaluation) {
            $outcome = null;
        } catch (ValidationException $exception) {
            if ($short === [] && ! $configValidated) {
                // The redacted validation text stays in the run error; the cause is a controlled catalog key.
                $logged = $this->error($exception, $checkId, $claimToken, $secrets, $context->crawlerHeaders, $timeout + ExecutionBudget::BookkeepingGrace);
                $outcome = new CheckOutcome(RunResult::Errored, EngineMessage::ConfigInvalid, [], null, error: $logged->error);
            } elseif ($short === []) {
                $outcome = $this->error($exception, $checkId, $claimToken, $secrets, $context->crawlerHeaders, $timeout + ExecutionBudget::BookkeepingGrace);
            } else {
                $outcome = new CheckOutcome(RunResult::Errored, 'checks.secret_too_short', [], null,
                    error: new CheckError($exception::class, __('checks.secret_too_short')));
            }
        } catch (Throwable $exception) {
            $outcome = $this->error($exception, $checkId, $claimToken, $secrets, $context->crawlerHeaders, $timeout + ExecutionBudget::BookkeepingGrace);
        }

        $refused = $context->policyRefusals->first();
        if ($refused instanceof FetchFailure && $outcome instanceof CheckOutcome) {
            // One boundary owns classification, including refusals an addon collected as failed findings.
            $cause = $refused->policySummary();
            $outcome = new CheckOutcome(RunResult::Errored, $cause['key'], $cause['params'], null,
                evidence: $outcome->evidence, error: new CheckError(FetchException::class, $refused->detail()));
        }

        $recorded = $this->recorder->record(new RecordableRun($checkId, $claimToken, $context->startedAt, CarbonImmutable::now('UTC'), $outcome, $context->acceptanceRevision));
        if (! $recorded->accepted) {
            $type->persistence()?->discard($context->runId, $claimToken);
        }
    }

    /** Queue timeout/failure callback; the recorder fences a replaced or already accepted claim. */
    public function failed(int $checkId, string $claimToken, Throwable $exception, int $jobTimeout): void
    {
        $check = Check::query()->find($checkId);
        if (! $check instanceof Check || $check->claim_token !== $claimToken || ! $check->run_started_at instanceof CarbonImmutable) {
            return;
        }
        $error = $this->error($exception, $checkId, $claimToken, array_replace($check->config_secrets ?? [], ['run_headers' => $check->run_headers ?? []]), $check->site->crawler_headers ?? [], $jobTimeout);
        $recorded = $this->recorder->record(new RecordableRun($checkId, $claimToken, $check->run_started_at, CarbonImmutable::now('UTC'), $error));
        if (! $recorded->accepted && $check->active_run_id !== null) {
            $this->registry->get($check->type)->persistence()?->discard($check->active_run_id, $claimToken);
        }
    }

    private function start(int $checkId, string $claimToken, int $timeout): ?CheckContext
    {
        return DB::transaction(function () use ($checkId, $claimToken, $timeout): ?CheckContext {
            $query = Check::query()->whereKey($checkId);
            $query->getQuery()->lockForUpdate();
            $check = $query->first();
            $at = CarbonImmutable::now('UTC');
            if (! $check instanceof Check || $check->claim_token !== $claimToken || $check->run_started_at !== null) {
                Log::notice('Discarded stale or already started check job.', ['check_id' => $checkId, 'claim_token' => $claimToken]);

                return null;
            }
            $site = $check->site()->getQuery()->first();
            if ($site === null || ! $check->enabled || $site->paused_at !== null || $site->marked_for_deletion_at !== null || $this->registry->availability($check->type)->reason !== null) {
                $attempt = CheckRunAttempt::query()->with('actor')->where('claim_token', $claimToken)->firstOrFail();
                $attempt->update(['phase' => RunPhase::Skipped, 'finished_at' => $at, 'note' => 'check_unavailable']);
                if ($site !== null) {
                    LiveSignal::forRun('run.finished', $site, $check->id, actor: $attempt->actor?->name, outcome: RunPhase::Skipped, attemptId: $attempt->id);
                }
                $check->forceFill(['awaiting_result_since' => null, 'lost_attempts' => 0, 'last_loss_started_at' => null, 'claim_token' => null, 'dispatched_at' => null, 'lease_expires_at' => null, 'run_started_at' => null, 'active_run_id' => null, 'manual_run' => false])->save();
                // The skip stores no result, so it sends no state.changed; a cleared mark still moves the site off pending.
                if (CheckPending::clear($check) && $site !== null) {
                    LiveSignal::forSite($site, ['check' => $check->id]);
                }

                return null;
            }
            if (! $check->lease_expires_at instanceof CarbonImmutable) {
                throw new \LogicException('A claimed check must have a queue lease.');
            }
            /** @var int $runId */
            $runId = DB::scalar("SELECT nextval('check_runs_id_seq')");
            $check->forceFill(['run_started_at' => $at, 'active_run_id' => $runId,
                'lease_expires_at' => $this->clock->now()->addSeconds(ExecutionBudget::runningLease($timeout))])->save();
            $attempt = CheckRunAttempt::query()->with('actor')->where('claim_token', $claimToken)->firstOrFail();
            $attempt->update(['phase' => RunPhase::Running, 'started_at' => $at]);
            LiveSignal::forRun('run.started', $site, $check->id, $at, $attempt->actor?->name);

            $config = $check->executionConfig();
            if ($check->run_headers !== null) {
                $config['http_client_headers'] = $check->run_headers;
            }

            return new CheckContext($site->id, $check->id, $runId, $claimToken, $site->url, $at, $at->addSeconds($timeout),
                $config, $site->crawler_headers ?? [], $this->previousResult($check), $this->history, manualRun: $check->manual_run, uptimeIntervalSeconds: $check->type === CheckKey::Performance ? app(UptimeDependency::class)->check($site->id)?->interval_seconds : null,
                confirmsAlert: AlertConfirmation::awaitsConfirmingRun($check, $site));
        });
    }

    private function previousResult(Check $check): ?PreviousResult
    {
        if ($check->latest_run_result === CheckResult::Pending) {
            return null;
        }
        if ($check->latest_run_id === null || ! $check->latest_run_ended_at instanceof CarbonImmutable || $check->summary === null) {
            throw new \LogicException('Completed check snapshot is missing its run identity or summary.');
        }
        $reference = new RunReference($check->latest_run_id, $check->latest_run_ended_at);
        $run = CheckRun::forRun($reference)->first();

        return new PreviousResult($reference, RunResult::from($check->latest_run_result->value), $check->latest_run_started_at,
            $check->summary, $check->summary_params ?? [], $run?->metrics);
    }

    /**
     * @param  array<string, mixed>  $secrets
     * @param  list<array{name: string, value: string}>  $crawlerHeaders
     */
    private function error(Throwable $exception, int $checkId, string $claimToken, #[\SensitiveParameter] array $secrets, #[\SensitiveParameter] array $crawlerHeaders, int $jobTimeout): CheckOutcome
    {
        $message = SecretRedactor::redact($exception->getMessage(), [$secrets, array_column($crawlerHeaders, 'value')]);
        $message = mb_substr($message === '' ? 'Check execution did not complete.' : $message, 0, 2048);
        $trace = array_map(static function (array $frame): array {
            unset($frame['args'], $frame['object']);

            return $frame;
        }, $exception->getTrace());
        Log::error('Check execution raised an exception.', [
            'check_id' => $checkId, 'claim_token' => $claimToken, 'exception_class' => $exception::class,
            'message' => $message, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'trace' => $trace,
        ]);

        $failure = $exception instanceof FetchException || $exception instanceof TargetAvailabilityException ? $exception->failure : null;
        $summary = match ($failure?->kind) {
            FetchFailureKind::Tls => $failure->tlsSummary ?? ($failure->tlsReason ?? TlsFailureReason::Handshake)->summary(''),
            FetchFailureKind::PrivateTarget, FetchFailureKind::RedirectBlocked => $failure->policySummary(),
            default => match (true) {
                $exception instanceof TargetAvailabilityException && $failure?->kind === FetchFailureKind::HttpStatus => ['key' => 'causes.http', 'params' => ['status' => $failure->status]],
                $failure instanceof FetchFailure => $failure->summary(),
                $exception instanceof TimeoutExceededException => ['key' => EngineMessage::Classes[$exception::class], 'params' => [
                    'seconds' => $jobTimeout]],
                $exception instanceof WorkerMemoryExhausted => ['key' => 'causes.engine.worker_memory_exhausted', 'params' => [
                    'setting' => WorkerMemoryBudget::CrawlLimitSetting, 'value' => config()->integer('heptapod.worker_memory.crawl_php_limit_mib')]],
                // Other text is not ours (or a new engine message the CI check rejects); it stays untrusted error_message.
                default => EngineMessage::forError($exception::class, $message) ?? ['key' => 'checks.engine.errored', 'params' => []],
            },
        };

        return new CheckOutcome(RunResult::Errored, $summary['key'], $summary['params'], null,
            error: new CheckError($exception::class, $message),
            availabilityFailure: $exception instanceof TargetAvailabilityException ? AvailabilityFailure::fromFetchFailure($failure) : null);
    }
}

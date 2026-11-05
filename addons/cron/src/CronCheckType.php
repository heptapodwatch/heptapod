<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckHistoryProvider;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\CheckRunPresenter;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\GuardsAcceptance;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Contracts\TaskDefinitionSchemaProvider;

final readonly class CronCheckType implements CheckHistoryProvider, CheckRunPresenter, CheckType, GuardsAcceptance, MailEvidenceProvider, TaskDefinitionSchemaProvider
{
    public function __construct(private CronPersistence $store, private CronDefinitionRevision $revision) {}

    public function revision(int $checkId): int
    {
        return $this->revision->current($checkId);
    }

    public function requiresSerialization(int $checkId): bool
    {
        return $this->revision->requiresSerialization($checkId);
    }

    public function serializeNext(int $checkId, bool $required): void
    {
        $this->revision->serializeNext($checkId, $required);
    }

    public function history(int $checkId, bool $canViewCapabilities = false): CronTasksDetailData
    {
        return $this->store->tasksDetail($checkId, $canViewCapabilities);
    }

    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        $name = $run->event?->payload['component_name'] ?? $run->event?->payload['name'] ?? null;
        $result = $run->event?->payload['result'] ?? null;
        if ($name === null && $result === null) {
            if ($run->result === RunResult::Errored) {
                return null;
            }
            // Task totals pluralize on the total ("1 task", "2 tasks"); retained runs carry no count of their own.
            if (in_array($run->summary, ['cron.tasks_on_time', 'cron.tasks_completed_late'], true) && is_int($run->summaryParams['total'] ?? null)) {
                return new CheckRunPresentationData([new CheckRunPresentationPartData($run->summary,
                    [...$run->summaryParams, 'count' => $run->summaryParams['total']])]);
            }
            $key = match ($run->summary) {
                'cron.tasks_failed' => 'cron.presentation.failed',
                'cron.tasks_failed_more' => 'cron.presentation.failed_more',
                'cron.tasks_late' => 'cron.presentation.late',
                'cron.tasks_late_named', 'cron.tasks_late_named_one' => 'cron.presentation.late_named',
                'cron.tasks_late_more' => 'cron.presentation.late_more',
                default => null,
            };

            if ($key === null) {
                return null;
            }
            $count = $run->summaryParams[str_starts_with($key, 'cron.presentation.failed') ? 'failed' : 'warning'] ?? null;
            $params = is_int($count) ? [...$run->summaryParams, 'count' => $count] : $run->summaryParams;

            return new CheckRunPresentationData([new CheckRunPresentationPartData($key, $params,
                untrusted: ['names' => str_starts_with($key, 'cron.presentation.failed') ? 'failing_tasks' : 'late_tasks'])]);
        }
        if (! is_string($name) || ! is_string($result)) {
            throw new \LogicException('Retained task presentation requires its name and result.');
        }
        $key = match ($result) {
            'failed' => 'cron.task_failed', 'warning' => 'cron.task_late', 'succeeded' => 'cron.task_succeeded',
            default => throw new \LogicException('Invalid retained task notification result.'),
        };

        return new CheckRunPresentationData([new CheckRunPresentationPartData('cron.presentation.task', ['name' => $name], ['result' => $key], untrusted: ['name' => 'task'])]);
    }

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'cron.mail_event.check.failing',
            'check.warning' => 'cron.mail_event.check.warning',
            'check.recovered' => 'cron.mail_event.check.recovered',
            'scheduled_task.missed' => 'cron.mail_event.scheduled_task.missed',
            'cron.task_result_changed' => 'cron.mail_event.cron.task_result_changed',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'cron.task_result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        $name = $event->payload['component_name'] ?? $event->payload['name'] ?? null;
        if (is_string($name)) {
            $result = $event->payload['result'] ?? null;
            if (! is_string($result)) {
                throw new \LogicException('Task mail requires its retained result.');
            }
            $key = match ($result) {
                'failed' => 'cron.task_failed', 'warning' => 'cron.task_late', 'succeeded' => 'cron.task_succeeded',
                default => throw new \LogicException('Invalid retained task mail result.'),
            };
            $rows = [['label' => __('cron.mail_task', locale: $locale), 'value' => new Cell($name, mono: true)],
                ['label' => __('cron.mail_result', locale: $locale), 'value' => new Cell(__($key, locale: $locale), tone: $result === 'failed' ? MailTone::Failed : ($result === 'warning' ? MailTone::Warning : MailTone::Recovered))]];
            $message = $event->payload['failure_message'] ?? null;
            if (is_string($message) && $message !== '') {
                $rows[] = ['label' => __('cron.mail_message', locale: $locale), 'value' => new Cell(mb_strcut($message, 0, 1024, 'UTF-8'))];
            }
            $started = $event->payload['failure_started_at'] ?? null;
            if (is_string($started)) {
                $at = CarbonImmutable::parse($started)->setTimezone($timezone);
                $at->locale($locale);
                $rows[] = ['label' => __('cron.mail_failure_started', locale: $locale), 'value' => new Cell($at->isoFormat('ll, HH:mm z'), mono: true)];
            }

            return [new FactsBlock(__('cron.mail_task_result', locale: $locale), $rows)];
        }
        if ($run->metrics === null) {
            return [];
        }
        $metrics = CronMetrics::from($run->metrics);

        return [new FactsBlock(__('cron.mail_task_result', locale: $locale), [
            ['label' => __('cron.task_failed', locale: $locale), 'value' => new Cell((string) $metrics->failed)],
            ['label' => __('cron.task_late', locale: $locale), 'value' => new Cell((string) $metrics->warning)],
            ['label' => __('cron.task_succeeded', locale: $locale), 'value' => new Cell((string) $metrics->succeeded)],
        ])];
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('cron.mail_details_not_retained', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        return new CheckDefinition(
            CheckKey::Cron, 'checks.cron.label', 'clock', 'checks-fast', 30, 60, 1,
            ExecutionMode::Push, new Schema([]),
            new Schema([
                'succeeded' => new Field(FieldType::Integer, ['required', 'min:0']),
                'warning' => new Field(FieldType::Integer, ['required', 'min:0']),
                'failed' => new Field(FieldType::Integer, ['required', 'min:0']),
                'pending' => new Field(FieldType::Integer, ['required', 'min:0']),
            ]), ['cron.task_result_changed'], recommended: false,
        );
    }

    private function taskResultSummary(CheckResult $result): string
    {
        return match ($result) {
            CheckResult::Succeeded => 'cron.task_succeeded',
            CheckResult::Failed => 'cron.task_failed',
            CheckResult::Warning => 'cron.task_late',
            CheckResult::Pending, CheckResult::Errored => throw new \LogicException('A cron task result-change event needs a final state.'),
        };
    }

    public function taskDefinitionSchema(): Schema
    {
        return CronDefinitions::schema();
    }

    public function persistence(): RunPersistence
    {
        return $this->store;
    }

    public function execute(CheckContext $context): CheckOutcome|NoResultReason
    {
        $context = $context->acceptanceRevision === null ? $context->withAcceptanceRevision($this->revision($context->checkId)) : $context;
        $counts = ['succeeded' => 0, 'warning' => 0, 'failed' => 0, 'pending' => 0];
        $completedAfterGrace = 0;
        $events = [];
        $failedNames = [];
        $warningNames = [];
        $nextDueAt = null;

        foreach (CronCheck::query()->where('check_id', $context->checkId)->lazyById(1000)->chunk(1000) as $tasks) {
            $rows = [];
            foreach ($tasks as $task) {
                // A failure ping that a later success overwrote before this evaluation still counts once.
                $result = $context->startedAt->greaterThanOrEqualTo($task->next_due_at->addMinutes($task->graceMinutes()))
                    || $task->failure_revision > $task->evaluated_failure_revision
                    ? CheckResult::Failed
                    : $task->aggregate_result;
                if ($result === CheckResult::Errored) {
                    throw new \LogicException('Cron task results cannot be engine errors.');
                }
                $counts[$result->value]++;
                if ($result === CheckResult::Failed && count($failedNames) < 4) {
                    $failedNames[] = $task->name;
                }
                if ($result === CheckResult::Succeeded && $task->late_by_seconds > 0) {
                    $completedAfterGrace++;
                }
                if ($result === CheckResult::Warning && count($warningNames) < 4) {
                    $warningNames[] = $task->name;
                }
                if ($nextDueAt === null || $task->next_due_at->lessThan($nextDueAt)) {
                    $nextDueAt = $task->next_due_at;
                }
                if ($result === CheckResult::Pending) {
                    continue;
                }
                $newFailure = $result === CheckResult::Failed && $task->failure_revision > $task->evaluated_failure_revision;
                $failureMessage = $newFailure ? ($task->ping_meta['failure_message'] ?? null) : null;
                // Keep the accepted component baseline, including an unknown
                // pre-upgrade baseline; never borrow the aggregate incident time.
                $failureAt = $result === CheckResult::Failed
                    ? ($task->evaluated_result === CheckResult::Failed ? $task->evaluated_failure_at : $context->startedAt)
                    : null;
                $rows[] = [
                    'cron_check_id' => $task->id,
                    'result' => $result->value,
                    'ping_revision' => $task->ping_revision,
                    'failure_revision' => $task->failure_revision,
                    'failure_message' => $failureMessage,
                    'late_by_seconds' => $task->late_by_seconds,
                    'failure_started_at' => $failureAt?->toIso8601String(),
                ];
                if ($task->evaluated_result !== $result || $newFailure) {
                    $events[] = new AddonEvent(
                        'cron.task_result_changed',
                        "cron:{$task->id}:{$context->runId}:{$result->value}:{$task->failure_revision}",
                        3,
                        ['component_name' => $task->name,
                            'failure_started_at' => ($failureAt ?? $task->evaluated_failure_at)?->toIso8601String(),
                            'recovered_at' => $result === CheckResult::Succeeded && in_array($task->evaluated_result, [CheckResult::Failed, CheckResult::Warning], true) ? $context->startedAt->toIso8601String() : null,
                            'cron_check_id' => $task->id, 'name' => $task->name, 'from_result' => $task->evaluated_result?->value, 'result' => $result->value, 'late_by_seconds' => $task->late_by_seconds, 'failure_message' => $failureMessage, 'coalesced_count' => $newFailure ? $task->failure_revision - $task->evaluated_failure_revision : 0,
                            'summary' => $this->taskResultSummary($result),
                            'summary_params' => ['presentation' => 'direct']],
                        new SuppressionSubject($context->checkId, SuppressionComponent::Cron, $task->id),
                    );
                }
            }
            if ($rows !== []) {
                $this->store->stage($context, 'task_results', $rows);
            }
        }

        if (array_sum($counts) === 0) {
            return new CheckOutcome(RunResult::Succeeded, 'cron.no_tasks', ['resolution_key' => 'cron.resolved_no_tasks'], new CronMetrics(...$counts));
        }

        if ($counts['succeeded'] + $counts['warning'] + $counts['failed'] === 0) {
            return NoResultReason::CronTasksPending;
        }

        $result = $counts['failed'] > 0 ? RunResult::Failed : ($counts['warning'] > 0 ? RunResult::Warning : RunResult::Succeeded);

        $summary = match (true) {
            $counts['failed'] > 0 => 'cron.tasks_failed',
            $counts['warning'] > 0 => 'cron.tasks_late',
            $counts['pending'] > 0 => 'cron.tasks_pending',
            $completedAfterGrace > 0 => 'cron.tasks_completed_late',
            default => 'cron.tasks_on_time',
        };
        $params = [...$counts, 'total' => array_sum($counts)];
        if ($summary === 'cron.tasks_completed_late') {
            $params['late'] = $completedAfterGrace;
        }
        if ($failedNames !== []) {
            // Bound the title even when many components fail; counts still include every task.
            $visible = CheckRunPresentationData::headlineCount($counts['failed'], 3);
            $params['names'] = implode(', ', array_slice($failedNames, 0, $visible));
            $params['remaining'] = $counts['failed'] - $visible;
            if ($params['remaining'] >= 2) {
                $summary = 'cron.tasks_failed_more';
            }
        }
        if ($summary === 'cron.tasks_late' && $warningNames !== []) {
            $visible = CheckRunPresentationData::headlineCount($counts['warning'], 3);
            $params['names'] = implode(', ', array_slice($warningNames, 0, $visible));
            $params['remaining'] = $counts['warning'] - $visible;
            $summary = $params['remaining'] >= 2 ? 'cron.tasks_late_more' : ($counts['warning'] === 1 ? 'cron.tasks_late_named_one' : 'cron.tasks_late_named');
        }
        if ($summary === 'cron.tasks_on_time' && $nextDueAt !== null) {
            $params['next_due_at_epoch'] = $nextDueAt->timestamp;
        }

        return new CheckOutcome($result, $summary, $params, new CronMetrics(...$counts), events: $events);
    }
}

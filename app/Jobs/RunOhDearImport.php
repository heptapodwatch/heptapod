<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Heptapod\Actions\CreateClient;
use Heptapod\Actions\ImportOhDearAccount;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Events\LiveSignal;
use Heptapod\Models\ImportRun;
use Heptapod\Models\Site;
use Heptapod\Support\ImportTokenStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Imports one Oh Dear account for a queued import run. The payload carries only the run id, never the token. */
#[Tries(1)]
final class RunOhDearImport implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $runId)
    {
        $this->onConnection('redis');
        $this->onQueue('reports');
    }

    public function handle(ImportOhDearAccount $import, CreateClient $createClient): void
    {
        // Claim the queued run; a duplicate or late job finds a running or terminal run and does nothing.
        $claimed = ImportRun::query()->getQuery()->where('id', $this->runId)->where('status', ImportRunStatus::Queued->value)
            ->update(['status' => ImportRunStatus::Running->value, 'remote_error' => null, 'error' => null, 'finished_at' => null, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $run = ImportRun::query()->findOrFail($this->runId);
        LiveSignal::forImport();
        $token = ImportTokenStore::get($run->id);
        if ($token === null) {
            $this->finish($run, ImportRunStatus::Failed, 'token_expired');

            return;
        }
        $clientIds = $this->clientIds($run, $createClient);
        // Only the planned monitors are imported, each with its approved client; anything else is reported, not imported.
        $approved = [];
        foreach ($run->plan as $planned) {
            $sourceId = $planned['source_id'] ?? null;
            if (is_string($sourceId) || is_int($sourceId)) {
                $approved[(int) $sourceId] = $clientIds[(int) $sourceId] ?? null;
            }
        }
        $attempt = $run->attempts;
        $failedRows = 0;
        $plan = $import($token, false, $approved, function (ImportPlanRowData $row) use ($run, $attempt, &$failedRows): void {
            if ($row->operation === ImportOperation::Failed) {
                $failedRows++;
            }
            // Results are kept across attempts, keyed by source monitor: a revisited monitor replaces its row.
            $results = $run->results;
            /** @var array<string, mixed> $result */
            $result = [...Arr::except($row->toArray(), ['details']), 'attempt' => $attempt];
            $results[$row->source_id] = $result;
            $run->results = $results;
            $run->save();
            LiveSignal::forImport();
        });
        $run->remote_error = $plan->remote_error;
        if ($plan->remote_error === null && $failedRows === 0) {
            $this->finish($run, ImportRunStatus::Completed, null);
            ImportTokenStore::forget($run->id);

            return;
        }
        // Unread monitors or failed monitors: keep the token for "Run again" until its TTL expires.
        $this->finish($run, ImportRunStatus::Incomplete, null);
    }

    public function failed(Throwable $exception): void
    {
        $run = ImportRun::query()->find($this->runId);
        if ($run instanceof ImportRun && $run->status === ImportRunStatus::Running) {
            $this->finish($run, ImportRunStatus::Failed, 'unexpected');
        }
    }

    /**
     * New clients are created inside the transaction that records them on the run, so a crash or a retry adopts
     * the client instead of creating another. Only sites that do not exist yet need one: existing sites keep theirs.
     *
     * @return array<int, int>
     */
    private function clientIds(ImportRun $run, CreateClient $createClient): array
    {
        $assignments = $run->assignments ?? [];
        $existing = Site::query()->getQuery()->whereIn('oh_dear_id', array_map(intval(...), array_keys($assignments)))->pluck('oh_dear_id')->all();
        $clientIds = [];
        foreach ($assignments as $sourceId => $assignment) {
            if (in_array((int) $sourceId, $existing, true)) {
                continue;
            }
            $name = $assignment['new_client'];
            $clientIds[(int) $sourceId] = $assignment['client_id'] ?? ($name === null ? throw new \LogicException('Assignment without a client.')
                : DB::transaction(function () use ($run, $name, $createClient): int {
                    $query = ImportRun::query();
                    $query->getQuery()->lockForUpdate();
                    $locked = $query->findOrFail($run->id);
                    $created = $locked->created_client_ids;
                    if (! isset($created[$name])) {
                        $created[$name] = $createClient(['name' => $name])->id;
                        $locked->created_client_ids = $created;
                        $locked->save();
                    }
                    $run->created_client_ids = $created;

                    return $created[$name];
                }));
        }

        return $clientIds;
    }

    private function finish(ImportRun $run, ImportRunStatus $status, ?string $error): void
    {
        $run->status = $status;
        $run->error = $error;
        $run->finished_at = now()->toImmutable();
        $run->save();
        LiveSignal::forImport();
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Heptapod\Actions\CreateClient;
use Heptapod\Actions\ImportOhDearAccount;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Data\Import\ImportChannelRowData;
use Heptapod\Data\Import\ImportPlanData;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Exceptions\ImportAccessRevoked;
use Heptapod\Models\ImportRun;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Support\ImportTokenStore;
use Heptapod\Support\OhDearImportNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;
use Throwable;

/** Imports one Oh Dear account for a queued import run. The payload carries only the run id, never the token. */
#[Tries(1)]
final class RunOhDearImport implements ShouldQueue
{
    use Localizable;
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
        Context::add('import_run_id', $run->id);
        LiveSignal::forImportChange();
        // Setup, client creation and the import share one boundary: a local fault keeps the sites already written and
        // the token, so "Run again" can continue once it is fixed.
        // The fault may be the rejected save of this model's results: reload it so the terminal state does not retry that payload.
        ImportOhDearAccount::stopOnLocalFault(function () use ($run, $import, $createClient): void {
            try {
                $this->import($run, $import, $createClient);
            } catch (ImportAccessRevoked) {
                // Sites, clients and channels written so far are kept; the token goes, so only a new plan can continue.
                ImportTokenStore::forget($run->id);
                $this->finish($run, ImportRunStatus::Failed, 'access_revoked');
            }
        }, fn () => $this->finish($run->refresh(), ImportRunStatus::Failed, 'local_error'));
    }

    private function import(ImportRun $run, ImportOhDearAccount $import, CreateClient $createClient): void
    {
        $ensureAccess = $this->ensureStarterAccess($run);
        $ensureAccess();
        $token = ImportTokenStore::get($run->id);
        if ($token === null) {
            $this->finish($run, ImportRunStatus::Failed, 'token_expired');

            return;
        }
        if ($this->availabilityChanged($run->plan)) {
            ImportTokenStore::forget($run->id);
            $this->finish($run, ImportRunStatus::Failed, 'plan_changed');

            return;
        }
        $sections = array_values($run->sections->all());
        $clientIds = $this->clientIds($run, $createClient, $ensureAccess);
        $approvedChannels = [];
        $channelClientIds = [];
        foreach ($run->channel_plan as $row) {
            $sourceId = $row['source_id'] ?? null;
            if (! is_int($sourceId)) {
                throw new \LogicException('Channel plan row has no source id.');
            }
            $approvedChannels[$sourceId] = $row;
            if (isset($clientIds['channel:'.$sourceId])) {
                $channelClientIds[$sourceId] = $clientIds['channel:'.$sourceId];
            }
        }
        // Only the planned monitors are imported, each with its approved client; anything else is reported, not imported.
        $approved = [];
        foreach ($run->plan as $planned) {
            $sourceId = $planned['source_id'] ?? null;
            if (is_string($sourceId) || is_int($sourceId)) {
                $siteId = $planned['site_id'] ?? null;
                $approved[(int) $sourceId] = ['clientId' => $clientIds[(int) $sourceId] ?? null, 'siteId' => is_int($siteId) ? $siteId : null];
            }
        }
        $attempt = $run->attempts;
        $failedRows = 0;
        $progress = function (ImportPlanRowData $row) use ($run, $attempt, &$failedRows): void {
            // Results are kept across attempts, keyed by source monitor: a revisited monitor replaces its row.
            $results = $run->results;
            /** @var array<string, mixed> $result */
            $result = [...Arr::except($row->toArray(), ['details']), 'attempt' => $attempt];
            if ($row->operation === ImportOperation::Failed) {
                $failedRows++;
                // The CLI summary is stored only on a failed row: it is the user-safe reason the run page shows.
                $result['details'] = $row->details.' '.__('import.log_reference', ['run' => $run->id]);
            }
            $results[$row->source_id] = $result;
            $run->results = $results;
            $run->save();
            LiveSignal::forImport();
        };
        // Stored reasons are text, so they are written in the language of the run's owner, the only user who sees them.
        $locale = User::query()->whereKey($run->user_id)->value('locale');
        $plan = $this->withLocale(is_string($locale) ? $locale : config()->string('app.locale'), static fn (): ImportPlanData => $import($token, false, $sections, $approved, $progress, $approvedChannels, $channelClientIds, $ensureAccess));
        $run->remote_error = $plan->remote_error;
        if ($plan->remote_error === null) {
            /** @var list<array<string, mixed>> $channelResults */
            $channelResults = array_map(static fn (ImportChannelRowData $row): array => $row->toArray(), $plan->channels);
            $run->channel_results = $channelResults;
        }
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
     * @param  \Closure(): void  $ensureAccess
     * @return array<int|string, int>
     */
    private function clientIds(ImportRun $run, CreateClient $createClient, \Closure $ensureAccess): array
    {
        $assignments = $run->assignments ?? [];
        $existing = Site::query()->getQuery()->whereIn('oh_dear_id', array_filter(array_keys($assignments), is_int(...)))->pluck('oh_dear_id')->all();
        $clientIds = [];
        foreach ($assignments as $sourceId => $assignment) {
            if (in_array($sourceId, $existing, true)) {
                continue;
            }
            $name = $assignment['new_client'];
            $clientIds[$sourceId] = $assignment['client_id'] ?? ($name === null ? throw new \LogicException('Assignment without a client.')
                : DB::transaction(function () use ($run, $name, $createClient, $ensureAccess): int {
                    $ensureAccess();
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

    /**
     * The plan decided per site which checks this instance cannot run. If that changed since (e.g. the browser service
     * was installed or removed), the run would import something other than what was approved.
     *
     * @param  list<array<string, mixed>>  $plan
     */
    private function availabilityChanged(array $plan): bool
    {
        $unavailable = array_map(static fn (CheckKey $key): string => $key->value, app(CheckRegistry::class)->unavailableKeys());
        $codes = array_flip(OhDearImportNotices::Keys);
        foreach ($plan as $row) {
            /** @var list<string> $notices */
            $notices = $row['notices'] ?? [];
            $skipped = [];
            // Checks the plan treated as runnable: imported, or left out for their settings (invalid, setup, short secret).
            /** @var list<string> $considered */
            $considered = [...(array) ($row['checks'] ?? []), ...(array) ($row['disabled_checks'] ?? [])];
            if (is_int($row['cron_definitions'] ?? null) && $row['cron_definitions'] > 0) {
                $considered[] = CheckKey::Cron->value;
            }
            foreach ($notices as $notice) {
                $code = $codes[$notice] ?? '';
                [$kind, $type] = explode(':', $code, 2) + [1 => ''];
                if ($kind === 'availability') {
                    $skipped[] = $type;
                } elseif (in_array($kind, ['invalid', 'setup', 'short_secret'], true)) {
                    $considered[] = $type;
                } elseif ($code === 'cron:disabled_without_definitions') {
                    $considered[] = 'cron';
                }
            }
            if (array_diff($skipped, $unavailable) !== [] || array_intersect($considered, $unavailable) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Starting an import needs an active Owner or Admin (Settings → Import); the run writes a client, site or channel only
     * while its starter still is one. Each write calls this first inside its own transaction: the key share on the starter
     * waits for a removal or access change (they hold the user FOR UPDATE) and keeps one from committing until the write has.
     *
     * @return \Closure(): void
     */
    private function ensureStarterAccess(ImportRun $run): \Closure
    {
        return static function () use ($run): void {
            NotificationLocks::users([$run->user_id]);
            $starter = User::query()->find($run->user_id);
            if (! $starter instanceof User || $starter->disabled_at !== null || ! $starter->hasAnyRole(['Owner', 'Admin'])) {
                throw new ImportAccessRevoked;
            }
        };
    }

    private function finish(ImportRun $run, ImportRunStatus $status, ?string $error): void
    {
        $run->status = $status;
        $run->error = $error;
        $run->finished_at = now()->toImmutable();
        $run->save();
        LiveSignal::forImportChange();
    }
}

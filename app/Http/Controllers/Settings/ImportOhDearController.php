<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Concerns\AuthorizesSettingsOperator;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CronPingUrl;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Data\Ui\ImportOhDearPageData;
use Heptapod\Data\Ui\ImportOhDearRunPageData;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\ImportRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ImportOhDearController extends Controller
{
    use AuthorizesSettingsOperator;

    public function edit(Request $request): Response
    {
        $this->operator($request);
        $clients = Client::query();
        $clients->getQuery()->whereNull('archived_at')->orderByDesc('is_internal')->orderBy('name');

        return Inertia::render('settings/import-ohdear', new ImportOhDearPageData(
            clients: array_values($clients->get(['id', 'name', 'is_internal'])
                ->map(static fn (Client $client): array => ['id' => $client->id, 'name' => $client->name, 'is_internal' => $client->is_internal])->all()),
        ));
    }

    public function show(Request $request, string $run, CronTaskStore $tasks, CronPingUrl $pingUrl): Response
    {
        $user = $this->operator($request);
        $importRun = ImportRun::query()->findOrFail($run);
        // Plan order first; then monitors that appeared after the plan (reported as not in the plan).
        $rows = [];
        $results = $importRun->results;
        foreach ($importRun->plan as $planned) {
            $sourceId = $planned['source_id'] ?? null;
            if ((is_string($sourceId) || is_int($sourceId)) && isset($results[(string) $sourceId])) {
                $rows[] = $results[(string) $sourceId];
                unset($results[(string) $sourceId]);
            }
        }
        $rows = [...$rows, ...array_values($results)];
        $siteIds = array_values(array_filter(array_column($rows, 'site_id'), is_int(...)));
        $cron = [];
        if (in_array($importRun->status, [ImportRunStatus::Completed, ImportRunStatus::Incomplete], true) && $siteIds !== []) {
            $query = Check::query()->with('site');
            $query->getQuery()->whereIn('site_id', $siteIds)->where('type', CheckKey::Cron->value)->orderBy('site_id');
            $checks = $query->get();
            $tasksByCheck = [];
            if ($checks->isNotEmpty()) {
                foreach ($tasks->listForChecks(array_values($checks->map(static fn (Check $check): int => $check->id)->all())) as $task) {
                    $tasksByCheck[$task->checkId][] = $task;
                }
            }
            foreach ($checks as $check) {
                $site = $check->site ?? abort(404);
                $list = [];
                foreach ($tasksByCheck[$check->id] ?? [] as $task) {
                    $list[] = ['name' => is_string($task->definition['name'] ?? null) ? $task->definition['name'] : '', 'ping_url' => $pingUrl->for($task->uuid)];
                }
                if ($list !== []) {
                    $cron[] = ['site' => $site->friendly_name ?? $site->url, 'site_id' => $site->id, 'tasks' => $list];
                }
            }
        }
        $attempt = $importRun->attempts;

        return Inertia::render('settings/import-ohdear-run', new ImportOhDearRunPageData(
            importRun: [
                'id' => $importRun->id, 'status' => $importRun->status, 'expected' => $importRun->expected, 'attempts' => $attempt,
                'processed' => count(array_filter($rows, static fn (array $row): bool => ($row['attempt'] ?? null) === $attempt)),
                'rows' => $rows, 'remote_error' => $importRun->remote_error, 'error' => $importRun->error,
                'failed' => count(array_filter($rows, static fn (array $row): bool => ($row['operation'] ?? null) === 'failed' && ($row['attempt'] ?? null) === $attempt)),
                'failed_earlier' => count(array_filter($rows, static fn (array $row): bool => ($row['operation'] ?? null) === 'failed' && ($row['attempt'] ?? null) !== $attempt)),
                'started_at' => $importRun->started_at?->toIso8601String(), 'finished_at' => $importRun->finished_at?->toIso8601String(),
                'can_retry' => in_array($importRun->status, [ImportRunStatus::Incomplete, ImportRunStatus::Failed], true) && $importRun->user_id === $user->id,
                'cron' => $cron,
            ],
        ));
    }
}

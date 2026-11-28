<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Concerns\AuthorizesSettingsOperator;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CronPingUrl;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Contracts\ListPaging;
use Heptapod\Contracts\PaginationData;
use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\ImportOhDearPageData;
use Heptapod\Data\Ui\ImportOhDearRunPageData;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Enums\ImportSection;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\ImportRun;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ImportOhDearController extends Controller
{
    use AuthorizesSettingsOperator;

    public function edit(Request $request): Response
    {
        $this->operator($request);
        $internal = Client::query()->with(Client::AvatarRelations);
        $internal->getQuery()->where('is_internal', true);

        return Inertia::render('settings/import-ohdear', new ImportOhDearPageData(
            internal_client: ClientData::fromClient($internal->firstOrFail()),
            timezones: \DateTimeZone::listIdentifiers(),
            sections: ImportSection::cases(),
            plan_page_size: ListPageSize::resolve($request, 'import_plan_rows'),
        ))->with('instanceTimezone', app(InstanceSettings::class)->timezone);
    }

    public function show(Request $request, string $run, CronTaskStore $tasks, CronPingUrl $pingUrl, ListPaging $paging): Response
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
        $sections = array_values($importRun->sections->all());
        $siteIds = array_values(array_filter(array_column($rows, 'site_id'), is_int(...)));
        $cron = [];
        if (in_array(ImportSection::Cron, $sections, true) && in_array($importRun->status, [ImportRunStatus::Completed, ImportRunStatus::Incomplete], true) && $siteIds !== []) {
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
        $imported = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['operation'] ?? null, ['create', 'update', 'partial'], true)));
        $commonNotices = [];
        if (count($imported) > 1) {
            /** @var list<string> $firstNotices */
            $firstNotices = array_values(array_filter((array) ($imported[0]['notices'] ?? []), is_string(...)));
            foreach ($firstNotices as $notice) {
                if (array_all($imported, static fn (array $row): bool => in_array($notice, (array) ($row['notices'] ?? []), true))) {
                    $commonNotices[] = $notice;
                }
            }
        }
        $alertTiming = [
            'inherited' => count(array_filter($rows, static fn (array $row): bool => ($row['inherits_alert_timing'] ?? null) === true)),
            'overridden' => count(array_filter($rows, static fn (array $row): bool => ($row['inherits_alert_timing'] ?? null) === false)),
        ];
        $rowsPaginator = $paging->paginateList($rows, 'import_run_rows', 'page');
        $cronPaginator = $paging->paginateList($cron, 'import_run_cron');

        return Inertia::render('settings/import-ohdear-run', new ImportOhDearRunPageData(
            importRun: [
                'id' => $importRun->id, 'status' => $importRun->status, 'expected' => $importRun->expected, 'attempts' => $attempt,
                'processed' => count(array_filter($rows, static fn (array $row): bool => ($row['attempt'] ?? null) === $attempt)),
                'rows' => array_values($rowsPaginator->items()), 'rows_pagination' => PaginationData::fromPaginator($rowsPaginator),
                'common_notices' => $commonNotices, 'alert_timing' => $alertTiming,
                'remote_error' => $importRun->remote_error, 'error' => $importRun->error,
                'failed' => count(array_filter($rows, static fn (array $row): bool => ($row['operation'] ?? null) === 'failed' && ($row['attempt'] ?? null) === $attempt)),
                'failed_remote' => count(array_filter($rows, static fn (array $row): bool => ($row['operation'] ?? null) === 'failed' && ($row['attempt'] ?? null) === $attempt && in_array('import.notice.failed_remote', (array) ($row['notices'] ?? []), true))),
                'failed_earlier' => count(array_filter($rows, static fn (array $row): bool => ($row['operation'] ?? null) === 'failed' && ($row['attempt'] ?? null) !== $attempt)),
                'started_at' => $importRun->started_at?->toIso8601String(), 'finished_at' => $importRun->finished_at?->toIso8601String(),
                'can_retry' => in_array($importRun->status, [ImportRunStatus::Incomplete, ImportRunStatus::Failed], true) && $importRun->user_id === $user->id,
                'sections' => $sections, 'cron' => array_values($cronPaginator->items()), 'cron_pagination' => PaginationData::fromPaginator($cronPaginator), 'channels' => $importRun->channel_results,
            ],
        ));
    }
}

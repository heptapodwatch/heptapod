<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Contracts\ListPaging;
use Heptapod\Contracts\PaginationData;
use Heptapod\Data\Ui\SystemHealthPageData;
use Heptapod\Data\Ui\SystemPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Queries\ShellHealthReader;
use Heptapod\Queries\UpdateStatusReader;
use Heptapod\SystemHealth\HealthMetric;
use Heptapod\SystemHealth\ReadSystemHealth;
use Heptapod\SystemHealth\SystemHealthData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class SystemHealthController extends Controller
{
    public function edit(Request $request, ReadSystemHealth $health, ShellHealthReader $activity, ListPaging $paging): Response
    {
        $user = $this->viewer($request);

        return Inertia::render('settings/system', new SystemPageData(
            systemPage: Lazy::closure(fn (): SystemHealthPageData => $this->data($user, $health, $activity, $paging)),
        ));
    }

    public function __invoke(Request $request, ReadSystemHealth $health, UpdateStatusReader $updates, ShellHealthReader $activity, ListPaging $paging): JsonResponse
    {
        $page = $this->data($this->viewer($request), $health, $activity, $paging);
        /** @var array{reverb: array{error?: string|null}, browserServices: array<string, array{error?: string|null}>}&array<string, mixed> $data */
        $data = $page->health->toArray();
        unset($data['reverb']['error']);
        foreach (array_keys($data['browserServices']) as $service) {
            unset($data['browserServices'][$service]['error']);
        }

        return response()->json(['health' => $data, 'updates' => $updates->read(),
            'observed_at' => $page->observed_at, 'last_result_at' => $page->last_result_at, 'check_errors' => $page->check_errors,
            'system_issues_pagination' => $page->system_issues_pagination]);
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAnyRole(['Owner', 'Admin']), 403);

        return $user;
    }

    private function data(User $user, ReadSystemHealth $health, ShellHealthReader $activity, ListPaging $paging): SystemHealthPageData
    {
        $reading = $health();
        $issuesPaginator = $paging->paginateList($reading->systemIssues, 'system_issues')->withPath(route('settings.system.edit'));
        $safe = SystemHealthData::from([
            ...$reading->toArray(),
            'reverb' => new HealthMetric($reading->reverb->status, $reading->reverb->value),
            'browserServices' => array_map(static fn (HealthMetric $metric): HealthMetric => new HealthMetric($metric->status, $metric->value), $reading->browserServices),
            'systemIssues' => array_values($issuesPaginator->items()),
        ]);
        $checks = $activity->read($user);

        return new SystemHealthPageData($safe, now()->toIso8601String(), $checks->last_result_at, $checks->check_errors, PaginationData::fromPaginator($issuesPaginator));
    }
}

<?php

declare(strict_types=1);

use Heptapod\Http\Controllers\Api;
use Heptapod\Http\Middleware\RequireApiToken;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

// Public and token-free: the document holds no monitoring data. The global api-entry throttle (60/min per IP) applies.
Route::get('openapi.json', Api\OpenApiDocumentController::class)->name('api.getOpenApiDocument');

// Addons register routes in this group and use the same abilities and resource policies.
Route::middleware(['auth:sanctum', RequireApiToken::class, 'throttle:api'])->name('api.')->group(function (): void {
    Route::get('monitors/{monitor}/maintenance-periods', [Api\MaintenancePeriodController::class, 'index'])
        ->whereNumber('monitor')->middleware(CheckAbilities::class.':maintenance:read')->name('listMaintenancePeriods');
    Route::middleware(CheckAbilities::class.':maintenance:write')->group(function (): void {
        Route::post('monitors/{monitor}/start-maintenance', Api\StartMaintenanceController::class)->whereNumber('monitor')->name('startMaintenance');
        Route::post('monitors/{monitor}/stop-maintenance', Api\StopMaintenanceController::class)->whereNumber('monitor')->name('stopMaintenance');
        Route::post('maintenance-periods', [Api\MaintenancePeriodController::class, 'store'])->name('createMaintenancePeriod');
        Route::put('maintenance-periods/{maintenancePeriod}', [Api\MaintenancePeriodController::class, 'update'])
            ->whereNumber('maintenancePeriod')->name('updateMaintenancePeriod');
        Route::delete('maintenance-periods/{maintenancePeriod}', [Api\MaintenancePeriodController::class, 'destroy'])
            ->whereNumber('maintenancePeriod')->name('deleteMaintenancePeriod');
    });
    Route::get('monitors/{monitor}/cron-checks', [Api\CronTaskController::class, 'index'])->middleware(CheckAbilities::class.':checks:read')->name('listCronChecks');
    Route::middleware(CheckAbilities::class.':checks:write')->group(function (): void {
        Route::post('monitors/{monitor}/cron-checks', [Api\CronTaskController::class, 'create'])->name('createCronCheck');
        Route::post('monitors/{monitor}/cron-checks/sync', Api\SyncCronTasksController::class)->name('syncCronChecks');
        Route::put('cron-checks/{cronTask}', [Api\CronTaskController::class, 'update'])->whereNumber('cronTask')->name('updateCronCheck');
        Route::delete('cron-checks/{cronTask}', [Api\CronTaskController::class, 'destroy'])->whereNumber('cronTask')->name('deleteCronCheck');
    });
    Route::post('cron-checks/{cronTask}/snooze', Api\SnoozeCronTaskController::class)->whereNumber('cronTask')->middleware(CheckForAnyAbility::class.':checks:snooze,checks:write')->name('snoozeCronCheck');
    Route::post('cron-checks/{cronTask}/unsnooze', Api\UnsnoozeCronTaskController::class)->whereNumber('cronTask')->middleware(CheckForAnyAbility::class.':checks:snooze,checks:write')->name('unsnoozeCronCheck');
    Route::get('me', Api\MeController::class)->middleware(CheckAbilities::class.':identity:read')->name('getMe');
    Route::get('internal/incidents/{incident}/note', [Api\IncidentNoteController::class, 'show'])->whereNumber('incident')->middleware(CheckAbilities::class.':incidents:read')->name('internal.getIncidentNote');
    Route::put('internal/incidents/{incident}/note', [Api\IncidentNoteController::class, 'update'])->whereNumber('incident')->middleware(CheckAbilities::class.':incidents:note')->name('internal.saveIncidentNote');
    Route::post('internal/incidents/{incident}/snooze', [Api\IncidentRemindersController::class, 'store'])->whereNumber('incident')->middleware(CheckForAnyAbility::class.':checks:snooze,checks:write')->name('internal.snoozeIncident');
    Route::delete('internal/incidents/{incident}/snooze', [Api\IncidentRemindersController::class, 'destroy'])->whereNumber('incident')->middleware(CheckForAnyAbility::class.':checks:snooze,checks:write')->name('internal.unsnoozeIncident');
    Route::middleware(CheckAbilities::class.':monitors:read')->group(function (): void {
        Route::get('monitors', [Api\MonitorController::class, 'index'])->name('listMonitors');
        Route::get('monitors/url/{url}', Api\MonitorUrlController::class)->where('url', '.*')->name('getMonitorByUrl');
        Route::get('monitors/{monitor}', [Api\MonitorController::class, 'show'])->name('getMonitor');
    });
    Route::middleware(CheckAbilities::class.':monitors:write')->group(function (): void {
        Route::post('monitors', [Api\MonitorController::class, 'store'])->name('createMonitor');
        Route::put('monitors/{monitor}', [Api\MonitorController::class, 'update'])->name('updateMonitor');
        Route::delete('monitors/{monitor}', [Api\MonitorController::class, 'destroy'])->name('deleteMonitor');
    });
    Route::middleware(CheckAbilities::class.':incidents:read')->group(function (): void {
        Route::get('incidents', [Api\IncidentController::class, 'index'])->name('listIncidents');
        Route::get('incidents/{incident}', [Api\IncidentController::class, 'show'])->whereNumber('incident')->name('getIncident');
    });
    Route::get('activity', Api\ActivityController::class)->middleware(CheckAbilities::class.':activity:read')->name('listActivity');
    Route::get('upcoming', Api\UpcomingController::class)->middleware(CheckAbilities::class.':upcoming:read')->name('listUpcoming');
    Route::get('findings', Api\FindingsController::class)->middleware(CheckAbilities::class.':checks:read')->name('findings');
    Route::middleware(CheckAbilities::class.':checks:read')->group(function (): void {
        Route::get('monitors/{monitor}/check-summary/{checkType}', Api\CheckSummaryController::class)->name('getCheckSummary');
        Route::get('monitors/{monitor}/checks/{checkType}/runs', Api\CheckRunController::class)->name('listCheckRuns');
    });
    Route::middleware(CheckAbilities::class.':checks:write')->group(function (): void {
        Route::post('checks/{check}/enable', Api\EnableCheckController::class)->name('enableCheck');
        Route::post('checks/{check}/disable', Api\DisableCheckController::class)->name('disableCheck');
    });
    Route::post('checks/{check}/runs', [Api\RunController::class, 'store'])->whereNumber('check')->middleware(CheckForAnyAbility::class.':checks:run,checks:write')->name('createCheckRun');
    Route::get('runs/{attemptRef}', [Api\RunController::class, 'show'])->where('attemptRef', 'attempt:[0-9]{1,18}')->middleware(CheckAbilities::class.':checks:read')->name('getRun');
    Route::post('checks/{check}/request-run', Api\RequestCheckRunController::class)->middleware(CheckForAnyAbility::class.':checks:run,checks:write')->name('requestCheckRun');
    Route::post('checks/{check}/snooze', Api\SnoozeCheckController::class)->middleware(CheckForAnyAbility::class.':checks:snooze,checks:write')->name('snoozeCheck');
    Route::post('checks/{check}/unsnooze', Api\UnsnoozeCheckController::class)->middleware(CheckForAnyAbility::class.':checks:snooze,checks:write')->name('unsnoozeCheck');
    Route::get('monitors/{monitor}/uptime', Api\UptimeController::class)->middleware(CheckAbilities::class.':uptime:read')->name('getUptime');
    Route::get('monitors/{monitor}/http-uptime-metrics', Api\UptimeMetricsController::class)->middleware(CheckAbilities::class.':uptime:read')->name('httpUptimeMetrics');
    Route::get('monitors/{monitor}/downtime', Api\DowntimeController::class)->middleware(CheckAbilities::class.':downtime:read')->name('getDowntime');
    Route::get('tags', [Api\TagController::class, 'index'])->middleware(CheckAbilities::class.':tags:read')->name('listTags');
    Route::post('tags', [Api\TagController::class, 'store'])->middleware(CheckAbilities::class.':tags:write')->name('createTag');
});

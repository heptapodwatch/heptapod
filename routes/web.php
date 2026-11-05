<?php

use Heptapod\Http\Controllers\AcceptDnsSnapshotController;
use Heptapod\Http\Controllers\ActivityController;
use Heptapod\Http\Controllers\AddSiteController;
use Heptapod\Http\Controllers\ArchiveClientController;
use Heptapod\Http\Controllers\CancelCheckRunController;
use Heptapod\Http\Controllers\CheckRunAttemptController;
use Heptapod\Http\Controllers\CheckTabController;
use Heptapod\Http\Controllers\ClientDetailController;
use Heptapod\Http\Controllers\ClientLogoController;
use Heptapod\Http\Controllers\ClientMessageAttachmentController;
use Heptapod\Http\Controllers\ClientMessagePreviewController;
use Heptapod\Http\Controllers\ClientMessagesController;
use Heptapod\Http\Controllers\ClientSettingsController;
use Heptapod\Http\Controllers\ClientsIndexController;
use Heptapod\Http\Controllers\EnableSiteCheckController;
use Heptapod\Http\Controllers\EndMaintenanceController;
use Heptapod\Http\Controllers\EndSiteMaintenanceController;
use Heptapod\Http\Controllers\FindingsExportController;
use Heptapod\Http\Controllers\HealthSettingsProbeController;
use Heptapod\Http\Controllers\IncidentActionsController;
use Heptapod\Http\Controllers\IncidentAlertPreviewController;
use Heptapod\Http\Controllers\IncidentDetailController;
use Heptapod\Http\Controllers\IncidentExportController;
use Heptapod\Http\Controllers\IncidentsIndexController;
use Heptapod\Http\Controllers\InvitationController;
use Heptapod\Http\Controllers\MaintenanceController;
use Heptapod\Http\Controllers\MaintenanceSeriesController;
use Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController;
use Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController;
use Heptapod\Http\Controllers\Notifications\NotificationChannelsController;
use Heptapod\Http\Controllers\Notifications\NotificationChannelTestController;
use Heptapod\Http\Controllers\Notifications\NotificationExplanationController;
use Heptapod\Http\Controllers\Notifications\NotificationPreferencesController;
use Heptapod\Http\Controllers\Notifications\NotificationScheduleController;
use Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController;
use Heptapod\Http\Controllers\RefreshSiteIconController;
use Heptapod\Http\Controllers\RequestCheckRunController;
use Heptapod\Http\Controllers\RequestSiteChecksController;
use Heptapod\Http\Controllers\RotateCronPingUrlController;
use Heptapod\Http\Controllers\RunsController;
use Heptapod\Http\Controllers\SearchController;
use Heptapod\Http\Controllers\SiteIconController;
use Heptapod\Http\Controllers\SiteMonitoringController;
use Heptapod\Http\Controllers\SiteOverviewController;
use Heptapod\Http\Controllers\SiteProbeController;
use Heptapod\Http\Controllers\SiteSettingsController;
use Heptapod\Http\Controllers\SitesIndexController;
use Heptapod\Http\Controllers\UpcomingController;
use Heptapod\Http\Controllers\UpdateCheckSettingsController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::permanentRedirect('/', '/sites')->middleware(['auth', 'verified'])->name('home');

Route::middleware(['guest', 'throttle:6,1'])->group(function (): void {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->where('token', '[a-f0-9]{64}')->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'store'])->where('token', '[a-f0-9]{64}')->name('invitations.accept');
});

if (app()->environment(['local', 'testing'])) {
    Route::get('/dev/components', fn (): Response => Inertia::render('dev/components'))->name('dev.components');
}

if (app()->isLocal()) {
    Route::view('/dev-login', 'dev-login')->name('dev-login');
}

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::permanentRedirect('dashboard', '/sites')->name('dashboard');
    Route::get('upcoming', UpcomingController::class)->name('upcoming.index');
    Route::get('activity', ActivityController::class)->name('activity.index');
    Route::get('runs', RunsController::class)->name('runs.index');
    Route::get('search', SearchController::class)->name('search');
    Route::get('clients', ClientsIndexController::class)->name('clients.index');
    Route::get('clients/create', [ClientSettingsController::class, 'create'])->name('clients.create');
    Route::post('clients', [ClientSettingsController::class, 'store'])->name('clients.store');
    Route::get('clients/{client}', ClientDetailController::class)->whereNumber('client')->name('clients.show');
    Route::get('clients/{client}/logo/{hash}', ClientLogoController::class)->whereNumber('client')->where('hash', '[a-f0-9]{64}')->name('clients.logo');
    Route::get('clients/{client}/settings', [ClientSettingsController::class, 'edit'])->whereNumber('client')->name('clients.settings');
    Route::patch('clients/{client}', [ClientSettingsController::class, 'update'])->whereNumber('client')->name('clients.update');
    Route::post('clients/{client}/archive', ArchiveClientController::class)->whereNumber('client')->name('clients.archive');
    Route::get('clients/{client}/client-messages', [ClientMessagesController::class, 'index'])->whereNumber('client')->name('clients.messages.index');
    Route::get('clients/{client}/client-messages/{message}', [ClientMessagesController::class, 'show'])->whereNumber(['client', 'message'])->name('clients.messages.show');
    Route::get('clients/{client}/client-messages/{message}/attachment', ClientMessageAttachmentController::class)->whereNumber(['client', 'message'])->name('clients.messages.attachment');
    Route::post('clients/{client}/client-messages/preview', ClientMessagePreviewController::class)->whereNumber('client')->name('clients.messages.preview');
    Route::post('clients/{client}/client-messages', [ClientMessagesController::class, 'store'])->whereNumber('client')->name('clients.messages.store');
    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
    Route::put('maintenance/series/{series}', [MaintenanceSeriesController::class, 'update'])->whereNumber('series')->name('maintenance.series.update');
    Route::delete('maintenance/series/{series}', [MaintenanceSeriesController::class, 'destroy'])->whereNumber('series')->name('maintenance.series.destroy');
    Route::put('maintenance/{window}', [MaintenanceController::class, 'update'])->whereNumber('window')->name('maintenance.update');
    Route::delete('maintenance/{window}', [MaintenanceController::class, 'destroy'])->whereNumber('window')->name('maintenance.destroy');
    Route::post('maintenance/{window}/end', EndMaintenanceController::class)->whereNumber('window')->name('maintenance.end');
    Route::get('incidents', IncidentsIndexController::class)->name('incidents.index');
    Route::get('incidents/export', IncidentExportController::class)->name('incidents.export');
    Route::get('incidents/{incident}/alerts/{event}', IncidentAlertPreviewController::class)->whereNumber(['incident', 'event'])->name('incidents.alert-preview');
    Route::get('incidents/{incident}', IncidentDetailController::class)->whereNumber('incident')->name('incidents.show');
    Route::put('incidents/{incident}/note', [IncidentActionsController::class, 'update'])->whereNumber('incident')->name('incidents.note');
    Route::post('incidents/{incident}/snooze', [IncidentActionsController::class, 'store'])->whereNumber('incident')->name('incidents.snooze');
    Route::delete('incidents/{incident}/snooze', [IncidentActionsController::class, 'destroy'])->whereNumber('incident')->name('incidents.unsnooze');
    Route::get('sites', SitesIndexController::class)->name('sites.index');
    Route::get('sites/create', [AddSiteController::class, 'create'])->name('sites.create');
    Route::post('sites/probe', SiteProbeController::class)->middleware('throttle:site-probe')->name('sites.probe');
    Route::post('sites', [AddSiteController::class, 'store'])->name('sites.store');
    Route::get('sites/{site}', SiteOverviewController::class)->whereNumber('site')->name('sites.show');
    Route::get('sites/{site}/maintenance', [MaintenanceController::class, 'show'])->whereNumber('site')->name('sites.maintenance');
    Route::post('sites/{site}/maintenance/end', EndSiteMaintenanceController::class)->whereNumber('site')->name('sites.maintenance.end');
    Route::get('sites/{site}/icon/{hash}', [SiteIconController::class, 'show'])->whereNumber('site')->where('hash', '[a-f0-9]{64}')->name('sites.icon');
    Route::post('sites/{site}/icon', RefreshSiteIconController::class)->whereNumber('site')->name('sites.icon.refresh');
    Route::get('sites/{site}/settings', [SiteSettingsController::class, 'edit'])->whereNumber('site')->name('sites.settings');
    Route::patch('sites/{site}', [SiteSettingsController::class, 'update'])->whereNumber('site')->name('sites.update');
    Route::delete('sites/{site}', [SiteSettingsController::class, 'destroy'])->whereNumber('site')->name('sites.destroy');
    Route::post('sites/{site}/pause', [SiteMonitoringController::class, 'store'])->whereNumber('site')->name('sites.pause');
    Route::post('sites/{site}/resume', [SiteMonitoringController::class, 'destroy'])->whereNumber('site')->name('sites.resume');
    Route::get('sites/{site}/checks/{check}', CheckTabController::class)->whereNumber(['site', 'check'])->name('sites.checks.show');
    Route::get('sites/{site}/checks/{check}/findings.csv', FindingsExportController::class)->whereNumber(['site', 'check'])->name('sites.checks.findings');
    Route::patch('sites/{site}/checks/{check}', UpdateCheckSettingsController::class)->whereNumber(['site', 'check'])->name('sites.checks.update');
    Route::post('sites/{site}/checks/{check}/enable', EnableSiteCheckController::class)->whereNumber(['site', 'check'])->name('sites.checks.enable');
    Route::post('sites/{site}/checks/{check}/health/probe', HealthSettingsProbeController::class)
        ->whereNumber(['site', 'check'])->name('sites.checks.health.probe');
    Route::post('sites/{site}/checks/{check}/run', RequestCheckRunController::class)->whereNumber(['site', 'check'])->name('sites.checks.run');
    Route::get('sites/{site}/checks/{check}/attempts/{attempt}', CheckRunAttemptController::class)->whereNumber(['site', 'check', 'attempt'])->name('sites.checks.attempts.show');
    Route::post('sites/{site}/checks/{check}/accept-dns', AcceptDnsSnapshotController::class)
        ->whereNumber(['site', 'check'])->name('sites.checks.accept-dns');
    Route::post('sites/{site}/checks/{check}/tasks/{task}/rotate-url', RotateCronPingUrlController::class)
        ->whereNumber(['site', 'check', 'task'])->name('sites.checks.tasks.rotate-url');
    Route::post('sites/{site}/checks/{check}/cancel', CancelCheckRunController::class)->whereNumber(['site', 'check'])->name('sites.checks.cancel');
    Route::post('sites/{site}/run-all', RequestSiteChecksController::class)->whereNumber('site')->name('sites.run-all');
    Route::post('notification-channels', [NotificationChannelsController::class, 'store'])->name('notification-channels.store');
    Route::patch('notification-channels/{channel}', [NotificationChannelsController::class, 'update'])->whereNumber('channel')->name('notification-channels.update');
    Route::delete('notification-channels/{channel}', [NotificationChannelsController::class, 'destroy'])->whereNumber('channel')->name('notification-channels.destroy');
    Route::patch('notification-channels/{channel}/enabled', NotificationChannelEnabledController::class)->whereNumber('channel')->name('notification-channels.enabled');
    Route::post('notification-channels/{channel}/test', NotificationChannelTestController::class)->whereNumber('channel')
        ->middleware('throttle:notification-test')->name('notification-channels.test');
    Route::put('notification-channels/{channel}/preferences', NotificationPreferencesController::class)->whereNumber('channel')->name('notification-channels.preferences');
    Route::put('notification-channels/{channel}/site-mutes/{site}', [NotificationSiteMutesController::class, 'update'])->whereNumber(['channel', 'site'])->name('notification-channels.site-mutes.store');
    Route::delete('notification-channels/{channel}/site-mutes/{site}', [NotificationSiteMutesController::class, 'destroy'])->whereNumber(['channel', 'site'])->name('notification-channels.site-mutes.destroy');
    Route::patch('notification-schedules/{kind}/{id?}', NotificationScheduleController::class)->where('kind', 'team|user|client')->whereNumber('id')->name('notification-schedules.update');
    Route::get('notifications/explain', NotificationExplanationController::class)->name('notifications.explain');
    Route::get('clients/{client}/notifications', [ClientNotificationSettingsController::class, 'edit'])->whereNumber('client')->name('clients.notifications');
    Route::get('clients/{client}/notifications/data', [ClientNotificationSettingsController::class, 'show'])->whereNumber('client')->name('clients.notifications.data');
});

require __DIR__.'/settings.php';

// Missing browser pages need session/locale middleware; API misses keep Laravel's JSON 404.
// Any method and no CSRF: the fallback only answers 404 (it changes no state), so unknown non-GET URLs such as
// Reverb's /apps/* publish path stay 404 instead of turning into 405 (GET-only fallback) or 419 (CSRF first).
Route::any('{fallbackPlaceholder}', static fn () => abort(404))
    ->where('fallbackPlaceholder', '(?!api(?:/|$)).*')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->fallback();

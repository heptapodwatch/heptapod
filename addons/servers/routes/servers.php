<?php

declare(strict_types=1);

use Heptapod\Addons\Servers\Http\EnsureServersEnabled;
use Heptapod\Addons\Servers\Http\ServerReportController;
use Heptapod\Addons\Servers\Http\ServersController;
use Heptapod\Addons\Servers\Http\ServerSitesController;
use Illuminate\Support\Facades\Route;

// The routes always exist, so switching servers on needs no restart. Switched off, the agent path answers 404 before
// routing (AgentResponses) and the pages answer 404 (EnsureServersEnabled); any other method on these paths reaches the
// web fallback's 404 either way.
// The agent's machine-to-machine bearer reports bypass browser sessions and CSRF.
Route::post('agent/v1/report', ServerReportController::class)->middleware('throttle:server-reports')->name('servers.report');

// After the session starts, so the 404 renders like any missing page; before auth, so guests get the same 404.
Route::middleware(['web', EnsureServersEnabled::class, 'auth', 'verified'])->group(static function (): void {
    Route::get('servers', [ServersController::class, 'index'])->name('servers.index');
    Route::post('servers', [ServersController::class, 'store'])->name('servers.store');
    Route::get('servers/{server}', [ServersController::class, 'show'])->whereNumber('server')->name('servers.show');
    Route::patch('servers/{server}', [ServersController::class, 'update'])->whereNumber('server')->name('servers.update');
    Route::post('servers/{server}/token', [ServersController::class, 'rotateToken'])->whereNumber('server')->name('servers.token.rotate');
    Route::post('servers/{server}/snooze', [ServersController::class, 'snooze'])->whereNumber('server')->name('servers.snooze');
    Route::delete('servers/{server}/snooze', [ServersController::class, 'unsnooze'])->whereNumber('server')->name('servers.unsnooze');
    Route::get('servers/{server}/sites', [ServerSitesController::class, 'index'])->whereNumber('server')->name('servers.sites.index');
    Route::post('servers/{server}/sites', [ServerSitesController::class, 'store'])->whereNumber('server')->name('servers.sites.store');
    Route::delete('servers/{server}/sites/{site}', [ServerSitesController::class, 'destroy'])->whereNumber(['server', 'site'])->name('servers.sites.destroy');
    Route::delete('servers/{server}', [ServersController::class, 'destroy'])->whereNumber('server')->name('servers.destroy');
});

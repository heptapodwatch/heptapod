<?php

declare(strict_types=1);

use Heptapod\Addons\Servers\Http\EnsureServersEnabled;
use Heptapod\Addons\Servers\Http\ServerReportController;
use Heptapod\Addons\Servers\Http\ServersController;
use Illuminate\Support\Facades\Route;

// The routes always exist, so switching servers on needs no restart; switched off, they answer 404 (EnsureServersEnabled).
// The agent's machine-to-machine bearer reports bypass browser sessions and CSRF.
Route::post('agent/v1/report', ServerReportController::class)
    ->middleware([EnsureServersEnabled::class, 'throttle:server-reports'])->name('servers.report');

// After the session starts, so the 404 renders like any missing page; before auth, so guests get the same 404.
Route::middleware(['web', EnsureServersEnabled::class, 'auth', 'verified'])->group(static function (): void {
    Route::get('servers', [ServersController::class, 'index'])->name('servers.index');
    Route::post('servers', [ServersController::class, 'store'])->name('servers.store');
    Route::get('servers/{server}', [ServersController::class, 'show'])->whereNumber('server')->name('servers.show');
    Route::post('servers/{server}/token', [ServersController::class, 'rotateToken'])->whereNumber('server')->name('servers.token.rotate');
    Route::delete('servers/{server}', [ServersController::class, 'destroy'])->whereNumber('server')->name('servers.destroy');
});

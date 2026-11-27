<?php

declare(strict_types=1);

use Heptapod\Addons\Servers\Http\Api\ServersApiController;
use Heptapod\Addons\Servers\Http\EnsureServersEnabled;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Inside core's API group (routes/api.php). Switched off, every path answers 404 before the token is checked.
Route::middleware(EnsureServersEnabled::class)->group(static function (): void {
    Route::middleware(CheckAbilities::class.':servers:read')->group(static function (): void {
        Route::get('servers', [ServersApiController::class, 'index'])->name('listServers');
        Route::get('servers/{server}', [ServersApiController::class, 'show'])->whereNumber('server')->name('getServer');
        Route::get('servers/{server}/issues', [ServersApiController::class, 'issues'])->whereNumber('server')->name('listServerIssues');
    });
    Route::middleware(CheckAbilities::class.':servers:snooze')->group(static function (): void {
        Route::post('servers/{server}/snooze', [ServersApiController::class, 'snooze'])->whereNumber('server')->name('snoozeServer');
        Route::post('servers/{server}/unsnooze', [ServersApiController::class, 'unsnooze'])->whereNumber('server')->name('unsnoozeServer');
    });
});

<?php

use Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController;
use Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController;
use Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController;
use Heptapod\Http\Controllers\Settings\AlertSensitivityController;
use Heptapod\Http\Controllers\Settings\ApiTokensController;
use Heptapod\Http\Controllers\Settings\BackupController;
use Heptapod\Http\Controllers\Settings\ImportOhDearController;
use Heptapod\Http\Controllers\Settings\InstanceController;
use Heptapod\Http\Controllers\Settings\InvitationResendController;
use Heptapod\Http\Controllers\Settings\InvitationRevokeController;
use Heptapod\Http\Controllers\Settings\MembersController;
use Heptapod\Http\Controllers\Settings\PlanOhDearImportController;
use Heptapod\Http\Controllers\Settings\ProfileController;
use Heptapod\Http\Controllers\Settings\RetryOhDearImportController;
use Heptapod\Http\Controllers\Settings\SecurityController;
use Heptapod\Http\Controllers\Settings\StartOhDearImportController;
use Heptapod\Http\Controllers\Settings\SystemHealthController;
use Heptapod\Http\Controllers\Settings\UpdateStatusController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', fn (): RedirectResponse => to_route('profile.edit', status: 301));
    Route::get('settings/instance', [InstanceController::class, 'edit'])->name('settings.instance.edit');
    Route::get('settings/instance/data', [InstanceController::class, 'show'])->name('settings.instance.data');
    Route::patch('settings/instance', [InstanceController::class, 'update'])->name('settings.instance.update');
    Route::get('settings/system', [SystemHealthController::class, 'edit'])->name('settings.system.edit');
    Route::get('settings/system/data', SystemHealthController::class)->name('settings.system.data');
    Route::get('settings/backups/data', [BackupController::class, 'index'])->name('settings.backups.data');
    Route::post('settings/backups', [BackupController::class, 'store'])->middleware('throttle:backup-now')->name('settings.backups.store');
    Route::get('settings/updates', [UpdateStatusController::class, 'edit'])->name('settings.updates.edit');
    Route::get('settings/updates/data', [UpdateStatusController::class, 'show'])->name('settings.updates.status');
    Route::patch('settings/updates', [UpdateStatusController::class, 'update'])->name('settings.updates.update');
    Route::get('settings/members', [MembersController::class, 'edit'])->name('settings.members.index');
    Route::get('settings/members/data', [MembersController::class, 'index'])->name('settings.members.data');
    Route::post('settings/members/invitations', [MembersController::class, 'store'])->name('settings.members.invite');
    Route::post('settings/members/invitations/{invitation}/resend', InvitationResendController::class)->whereNumber('invitation')->name('settings.members.resend');
    Route::delete('settings/members/invitations/{invitation}', InvitationRevokeController::class)->whereNumber('invitation')->name('settings.members.revoke');
    Route::patch('settings/members/{member}', [MembersController::class, 'update'])->whereNumber('member')->name('settings.members.update');
    Route::delete('settings/members/{member}', [MembersController::class, 'destroy'])->whereNumber('member')->name('settings.members.remove');
    Route::get('settings/api-tokens', [ApiTokensController::class, 'edit'])->name('settings.api-tokens.edit');
    Route::get('settings/api-tokens/data', [ApiTokensController::class, 'index'])->name('settings.api-tokens.data');
    Route::post('settings/api-tokens', [ApiTokensController::class, 'store'])->name('settings.api-tokens.store');
    Route::delete('settings/api-tokens/{token}', [ApiTokensController::class, 'destroy'])->whereNumber('token')->name('settings.api-tokens.destroy');
    Route::get('settings/import/ohdear', [ImportOhDearController::class, 'edit'])->name('settings.import.ohdear.edit');
    Route::post('settings/import/ohdear/plan', PlanOhDearImportController::class)->middleware('throttle:6,1')->name('settings.import.ohdear.plan');
    Route::post('settings/import/ohdear/runs/{run}/start', StartOhDearImportController::class)->whereUuid('run')->middleware('throttle:6,1')->name('settings.import.ohdear.runs.start');
    Route::get('settings/import/ohdear/runs/{run}', [ImportOhDearController::class, 'show'])->whereUuid('run')->name('settings.import.ohdear.runs.show');
    Route::post('settings/import/ohdear/runs/{run}/retry', RetryOhDearImportController::class)->whereUuid('run')->middleware('throttle:6,1')->name('settings.import.ohdear.runs.retry');
    Route::get('settings/alerts', [AlertSensitivityController::class, 'edit'])->name('settings.alerts.edit');
    Route::put('settings/alerts', [AlertSensitivityController::class, 'update'])->name('settings.alerts.update');
    Route::get('settings/notifications', [OwnNotificationSettingsController::class, 'edit'])->name('settings.notifications.edit');
    Route::get('settings/notifications/data', [OwnNotificationSettingsController::class, 'show'])->name('settings.notifications.data');
    Route::get('settings/notifications/team', [TeamNotificationSettingsController::class, 'edit'])->name('settings.notifications.team');
    Route::get('settings/notifications/team/data', [TeamNotificationSettingsController::class, 'show'])->name('settings.notifications.team.data');
    Route::delete('settings/notifications/team/migration-report', DismissNotificationMigrationReportController::class)->name('settings.notifications.team.migration-report.dismiss');
});

Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');

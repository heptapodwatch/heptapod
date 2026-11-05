<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

it('projects updates for Admins without exposing Owner backup history', function (string $role): void {
    Storage::fake('backups');
    config(['heptapod.version' => 'v1.2.2', 'heptapod.updates.enabled_by_server' => false]);
    DB::table('backup_runs')->insert(['state' => 'failed', 'disk' => 'backups', 'http_status' => 403,
        'requested_at' => now(), 'completed_at' => now()]);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user)->get('/settings/updates')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('settings/updates')->where('auth.canViewUpdates', true)
        ->where('updatesPage.updates.installed_version', 'v1.2.2')
        ->where('updatesPage.updates.update_status', 'disabled_by_server')
        ->has('updatesPage.updates.evaluated_at')
        ->when($role === 'Admin', fn (Assert $page): Assert => $page->where('updatesPage.backups', null))
        ->when($role === 'Owner', fn (Assert $page): Assert => $page
            ->where('updatesPage.backups.runs.0.state', 'failed')
            ->where('updatesPage.backups.runs.0.http_status', 403)
            ->where('updatesPage.backups.schedule_utc', '02:30')
            ->has('updatesPage.backups.observed_at')));
    expect(DB::table('backup_runs')->count())->toBe(1);
})->with(['Owner', 'Admin']);

it('rejects guests and Members from the updates page', function (): void {
    $this->get('/settings/updates')->assertRedirect('/login');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->get('/settings/updates')->assertForbidden();
    $this->get('/settings/profile')->assertInertia(fn (Assert $page): Assert => $page->where('auth.canViewUpdates', false));
});

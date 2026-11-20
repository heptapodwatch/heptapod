<?php

declare(strict_types=1);

use Heptapod\Http\ListPageSize;
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

it('pages backup runs and archives with remembered sizes and preserves latest run and page URLs on refresh', function (): void {
    Storage::fake('backups');
    $name = config()->string('backup.backup.name');
    foreach (range(1, 12) as $day) {
        Storage::disk('backups')->put(sprintf('%s/2026-10-%02d-02-30-00.zip', $name, $day), str_repeat('x', $day * 1024));
    }
    $runIds = collect(range(1, 12))->map(fn (int $hour): int => DB::table('backup_runs')->insertGetId([
        'state' => $hour === 12 ? 'failed' : ($hour === 11 ? 'running' : 'complete'),
        'disk' => 'backups',
        'http_status' => $hour === 12 ? 500 : null,
        'requested_at' => now()->subHours(20 - $hour),
        'completed_at' => $hour === 11 ? null : now()->subHours(20 - $hour),
    ]))->values();
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();

    $first = $this->actingAs($owner)->get('/settings/updates?backup_runs_per_page=10&backup_archives_per_page=10')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('updatesPage.backups.runs', 10)
            ->where('updatesPage.backups.runs.0.id', $runIds[11])
            ->where('updatesPage.backups.runs_pagination.total', 12)
            ->where('updatesPage.backups.runs_pagination.last_page', 2)
            ->where('updatesPage.backups.runs_pagination.page_name', 'backup_runs_page')
            ->has('updatesPage.backups.archives', 10)
            ->where('updatesPage.backups.archives_pagination.total', 12)
            ->where('updatesPage.backups.archives_pagination.last_page', 2)
            ->where('updatesPage.backups.archives_pagination.page_name', 'backup_archives_page'));
    expect(json_decode((string) $first->getCookie(ListPageSize::Cookie, false)?->getValue(), true))
        ->toBe(['backup_archives' => 10, 'backup_runs' => 10]);

    // Page 2 respects the remembered cookie, returns the next rows, and keeps latest_run / has_active_run truthful.
    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['backup_runs' => 10, 'backup_archives' => 10]));
    $this->get('/settings/updates?backup_runs_page=2&backup_archives_page=2')->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('updatesPage.backups.runs', 2)
            ->where('updatesPage.backups.runs.0.id', $runIds[1])
            ->where('updatesPage.backups.runs.1.id', $runIds[0])
            ->where('updatesPage.backups.latest_run.id', $runIds[11])
            ->where('updatesPage.backups.latest_run.state', 'failed')
            ->where('updatesPage.backups.has_active_run', true)
            ->has('updatesPage.backups.archives', 2));

    // Background JSON refresh on page 2 keeps page 2 rows and points pager URLs back to /settings/updates.
    $refresh = $this->withCredentials()
        ->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['backup_runs' => 10, 'backup_archives' => 10]))
        ->getJson('/settings/backups/data?backup_runs_page=2&backup_archives_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'runs')
        ->assertJsonPath('runs.0.id', $runIds[1])
        ->assertJsonPath('latest_run.id', $runIds[11])
        ->assertJsonPath('has_active_run', true)
        ->assertJsonCount(2, 'archives');
    expect((string) $refresh->json('runs_pagination.previous_page_url'))->toContain('/settings/updates?')
        ->and((string) $refresh->json('archives_pagination.previous_page_url'))->toContain('/settings/updates?');

    $this->get('/settings/updates?backup_runs_per_page=7')->assertSessionHasErrors('backup_runs_per_page');
    $this->get('/settings/updates?backup_archives_per_page=7')->assertSessionHasErrors('backup_archives_per_page');

    // Permissions unchanged: Admin still gets null backups on page 2 and 403 on JSON refresh.
    $this->actingAs($admin)->get('/settings/updates?backup_runs_page=2')->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('updatesPage.backups', null));
    $this->actingAs($admin)->getJson('/settings/backups/data?backup_runs_page=2')->assertForbidden();
});

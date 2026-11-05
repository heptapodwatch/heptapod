<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Spatie\Permission\Models\Role;

it('can assign production roles and issue an owner token after migration without seeders', function (): void {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

    $assigned = [];
    foreach (['Owner', 'Admin', 'Member'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);
        $assigned[$role] = $user;
        expect($user->hasRole($role))->toBeTrue();
        if ($role === 'Owner') {
            $this->artisan('heptapod:token:create', ['user' => $user->email, '--name' => 'first-run', '--abilities' => '*'])->assertSuccessful();
            expect($user->tokens()->sole()->abilities)->toBe(['*']);
        }
    }

    $migration = require database_path('migrations/2026_10_02_120000_initialize_roles.php');
    $migration->up();
    $migration->down();
    expect(Role::query()->where('guard_name', 'web')->count())->toBe(3);
    foreach ($assigned as $role => $user) {
        expect($user->fresh()->hasRole($role))->toBeTrue();
    }
    $migration->up();
    expect(Role::query()->where('guard_name', 'web')->count())->toBe(3);
});

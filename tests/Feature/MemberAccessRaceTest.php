<?php

declare(strict_types=1);

use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

it('rechecks actor and target permissions after a concurrent role change', function (string $change, string $action): void {
    $roleIds = Role::query()->pluck('id')->all();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $target = User::factory()->create();
    $target->assignRole('Member');
    $client = Client::factory()->create();
    DB::commit();

    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $actor = Heptapod\Models\User::findOrFail((int) getenv('RACE_ACTOR'));
        $target = Heptapod\Models\User::findOrFail((int) getenv('RACE_TARGET'));
        try {
            if (getenv('RACE_ACTION') === 'update') {
                app(Heptapod\Actions\ChangeMemberAccess::class)($actor, $target, ['role' => 'Member', 'client_ids' => [(int) getenv('RACE_CLIENT')]]);
            } else {
                app(Heptapod\Actions\DeactivateMember::class)($actor, $target);
            }
            echo '204';
        } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            echo $exception->getStatusCode();
        }
        PHP;
    $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
        'RACE_ACTOR' => (string) $admin->id, 'RACE_TARGET' => (string) $target->id,
        'RACE_CLIENT' => (string) $client->id, 'RACE_ACTION' => $action,
    ], timeout: 30);
    $locked = false;
    try {
        DB::beginTransaction();
        DB::table('roles')->where('name', 'Owner')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
        $locked = true;
        $process->start();
        $deadline = hrtime(true) + 15_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND cardinality(pg_blocking_pids(pid)) > 0 AND query LIKE '%roles%'");
            if ($waiting > 0) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline && $process->isRunning());
        expect($waiting)->toBeGreaterThan(0, $process->getOutput().$process->getErrorOutput());
        ($change === 'actor' ? $admin : $target)->syncRoles([$change === 'actor' ? 'Member' : 'Owner']);
        DB::commit();
        $locked = false;
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        expect($process->getOutput())->toBe('403');
        expect($target->fresh()->disabled_at)->toBeNull();
        if ($change === 'target') {
            expect($target->fresh()->hasRole('Owner'))->toBeTrue();
        }
    } finally {
        if ($locked) {
            DB::rollBack();
        }
        $process->stop(1);
        $target->delete();
        $admin->delete();
        $owner->delete();
        $client->delete();
        Role::query()->whereNotIn('id', $roleIds)->delete();
        DB::beginTransaction();
    }
})->with([
    'target promoted before Admin update' => ['target', 'update'],
    'target promoted before Admin deactivation' => ['target', 'deactivate'],
    'Admin demoted before member update' => ['actor', 'update'],
]);

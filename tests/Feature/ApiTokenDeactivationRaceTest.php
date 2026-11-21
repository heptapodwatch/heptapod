<?php

declare(strict_types=1);

use Heptapod\Actions\DeactivateMember;
use Heptapod\Jobs\TerminateLiveConnections;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

/*
 * A token issued while the account is being removed must not outlive the removal. The removal runs in this connection and
 * holds its transaction open while a second process issues a token for the same, still loaded, account. Not a Gate test:
 * a subprocess and committed data (scripts/check-gate-tests.php).
 */
it('refuses a token issued while the account is being removed, so none survives the removal', function (): void {
    Queue::fake([TerminateLiveConnections::class]);
    $roleIds = Role::query()->pluck('id')->all();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Admin');
    DB::commit();
    $issue = new Process([PHP_BINARY, '-r', <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $member = Heptapod\Models\User::query()->findOrFail((int) getenv('RACE_USER'));
        try {
            app(Heptapod\Actions\CreateApiToken::class)($member, 'issued-during-removal', ['monitors:read']);
            echo 'issued';
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            echo 'refused:'.$exception->getStatusCode();
        }
        PHP], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'RACE_USER' => (string) $member->id], timeout: 30);
    try {
        DB::beginTransaction();
        app(DeactivateMember::class)($owner, $member);
        $issue->start();
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'");
            if ($waiting > 0 || ! $issue->isRunning()) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        DB::commit();
        $issue->wait();

        expect($issue->isSuccessful())->toBeTrue($issue->getErrorOutput())
            ->and(trim($issue->getOutput()))->toBe('refused:403')
            ->and($member->tokens()->count())->toBe(0);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->delete();
        DB::table('activity_log')->whereIn('causer_id', [$owner->id, $member->id])->delete();
        $member->delete();
        $owner->delete();
        Role::query()->whereNotIn('id', $roleIds)->delete();
        DB::beginTransaction();
    }
});

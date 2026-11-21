<?php

declare(strict_types=1);

use Heptapod\Actions\AcceptInvitation;
use Heptapod\Actions\DeactivateMember;
use Heptapod\Actions\Fortify\ResetUserPassword;
use Heptapod\Jobs\TerminateLiveConnections;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

/*
 * Revocations race requests that were authorized before them. The revocation runs in this connection and holds its
 * transaction open while a second process runs the request for the same account. Not a Gate test: a subprocess and
 * committed data (scripts/check-gate-tests.php).
 */

/** A PHP process booted against the test database that runs $script; RACE_USER and RACE_TOKEN are passed through. */
function revocationRaceProcess(string $script, User $member, string $token = ''): Process
{
    $boot = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        PHP;

    return new Process([PHP_BINARY, '-r', $boot.$script], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'),
        'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
        'RACE_USER' => (string) $member->id, 'RACE_TOKEN' => $token], timeout: 30);
}

/** Starts $process inside this connection's open transaction, waits until it blocks on a lock (or ends), runs $finish, commits. */
function commitWhileWaiting(Process $process, Closure $finish): void
{
    $process->start();
    $deadline = hrtime(true) + 20_000_000_000;
    do {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $waiting = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'");
        if ($waiting > 0 || ! $process->isRunning()) {
            break;
        }
        usleep(10000);
    } while (hrtime(true) < $deadline);
    $finish();
    DB::commit();
    $process->wait();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
}

/** @return array{User, User, Closure(): void} the owner, the member and the cleanup */
function revocationRaceAccounts(): array
{
    Queue::fake([TerminateLiveConnections::class]);
    $roleIds = Role::query()->pluck('id')->all();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Admin');

    return [$owner, $member, static function () use ($owner, $member, $roleIds): void {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->delete();
        DB::table('password_reset_tokens')->where('email', $member->email)->delete();
        DB::table('invitations')->where('email', $member->email)->delete();
        // The reactivated account's own channel.
        DB::table('notification_destinations')->where('user_id', $member->id)->delete();
        DB::table('activity_log')->whereIn('causer_id', [$owner->id, $member->id])->delete();
        $member->delete();
        $owner->delete();
        Role::query()->whereNotIn('id', $roleIds)->delete();
        DB::beginTransaction();
    }];
}

it('refuses a token issued during a revocation, so none survives it', function (string $revocation): void {
    [$owner, $member, $cleanup] = revocationRaceAccounts();
    $resetToken = Password::createToken($member);
    DB::commit();
    // Loaded before the revocation commits, as the issuing request authenticated it.
    $issue = revocationRaceProcess(<<<'PHP'
        $member = Heptapod\Models\User::query()->findOrFail((int) getenv('RACE_USER'));
        try {
            app(Heptapod\Actions\CreateApiToken::class)($member, 'issued-during-revocation', ['monitors:read']);
            echo 'issued';
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            echo 'refused:'.$exception->getStatusCode();
        }
        PHP, $member);
    try {
        DB::beginTransaction();
        match ($revocation) {
            'removal' => app(DeactivateMember::class)($owner, $member),
            'password reset' => app(ResetUserPassword::class)->reset($member, ['token' => $resetToken, 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1']),
        };
        commitWhileWaiting($issue, static fn (): null => null);

        expect(trim($issue->getOutput()))->toBe('refused:403')->and($member->tokens()->count())->toBe(0);
    } finally {
        $cleanup();
    }
})->with(['removal', 'password reset']);

it('refuses a password reset approved before the account was removed and reactivated', function (): void {
    [$owner, $member, $cleanup] = revocationRaceAccounts();
    $resetToken = Password::createToken($member);
    $invite = str_repeat('a', 64);
    Invitation::factory()->create(['email' => $member->email, 'token_hash' => hash('sha256', $invite), 'role' => 'Member', 'inviter_id' => $owner->id]);
    DB::commit();
    // The forgotten-password form: the broker approves the link before the reset waits for the account.
    $reset = revocationRaceProcess(<<<'PHP'
        $member = Heptapod\Models\User::query()->findOrFail((int) getenv('RACE_USER'));
        $response = app(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create('/reset-password', 'POST', [
            'token' => getenv('RACE_TOKEN'), 'email' => $member->email, 'password' => 'taken-over-password-1', 'password_confirmation' => 'taken-over-password-1',
        ]));
        echo $response->getStatusCode();
        PHP, $member, $resetToken);
    try {
        DB::beginTransaction();
        app(DeactivateMember::class)($owner, $member);
        commitWhileWaiting($reset, static fn (): User => app(AcceptInvitation::class)($invite, [
            'name' => 'Returned', 'password' => 'reactivated-password-1', 'password_confirmation' => 'reactivated-password-1',
        ]));

        expect(Hash::check('reactivated-password-1', (string) $member->refresh()->password))->toBeTrue()
            ->and($member->disabled_at)->toBeNull();
    } finally {
        $cleanup();
    }
});

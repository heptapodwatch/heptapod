<?php

declare(strict_types=1);

use Heptapod\Actions\ArchiveNotificationChannel;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

it('creates the first verified Owner with a generated password shown once', function (): void {
    expect(Artisan::call('heptapod:user:create-owner', ['--email' => 'alice@example.com']))->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('Owner created: alice@example.com')
        ->and(substr_count($output, 'Password (shown once): '))->toBe(1);
    preg_match('/Password \(shown once\): (\S+)/', $output, $matches);
    $user = User::query()->sole();
    expect($user->name)->toBe('alice')
        ->and($user->hasRole('Owner'))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check($matches[1], $user->password))->toBeTrue()
        ->and(strlen($matches[1]))->toBe(32);

    $this->artisan('heptapod:user:create-owner', ['--email' => 'other@example.com'])->assertFailed();
    $this->artisan('heptapod:user:create-owner', ['--email' => 'other@example.com', '--if-missing' => true])
        ->expectsOutput('Owner already exists; no account or password changed.')
        ->assertSuccessful();
    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->password)->toBe($user->password);
});

it('prints 200 generated passwords without changing their characters', function (): void {
    foreach (range(1, 200) as $attempt) {
        expect(Artisan::call('heptapod:user:create-owner', ['--email' => 'owner@example.com']))->toBe(0);
        $output = Artisan::output();
        preg_match('/^Password \(shown once\): (\S+)$/m', $output, $matches);
        $printedPassword = $matches[1] ?? '';

        expect($printedPassword)->toMatch('/\A[A-Za-z0-9_.-]{32}\z/')
            ->and(Hash::check($printedPassword, User::query()->sole()->password))->toBeTrue();

        // DeleteProfile refuses the last Owner; run its notification step (archive, then delete) directly.
        $owner = User::query()->sole();
        foreach (NotificationDestination::query()->where('user_id', $owner->id)->get() as $channel) {
            app(ArchiveNotificationChannel::class)($channel, ArchiveNotificationChannel::RecipientDeleted, null);
        }
        $owner->delete();
    }
});

it('reads a supplied password from stdin through the full production password rules without printing it', function (): void {
    app()->instance('env', 'production');
    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
    Http::preventStrayRequests();
    $command = Artisan::all()['heptapod:user:create-owner'];
    $input = new ArrayInput(['--email' => 'owner@example.com', '--name' => 'First Owner', '--password-stdin' => true], $command->getDefinition());
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, "unique-Supplied-password-2026!\n");
    rewind($stream);
    $input->setStream($stream);
    $output = new BufferedOutput;
    try {
        expect($command->run($input, $output))->toBe(0);
    } finally {
        fclose($stream);
    }
    Http::assertSentCount(1);
    expect($output->fetch())->not->toContain('unique-Supplied-password-2026!');
    $user = User::query()->sole();
    expect($user->name)->toBe('First Owner')
        ->and(Hash::check('unique-Supplied-password-2026!', $user->password))->toBeTrue();
});

it('reports validation messages for invalid or taken emails', function (string $email, string $message): void {
    User::factory()->create(['email' => 'taken@example.com']);
    expect(Artisan::call('heptapod:user:create-owner', ['--email' => $email]))->toBe(1);
    expect(Artisan::output())->toContain('Owner creation failed: '.$message);
    expect(User::query()->count())->toBe(1);
})->with([
    ['not-an-email', 'The email field must be a valid email address.'],
    ['taken@example.com', 'The email has already been taken.'],
]);

it('reports the weak stdin password rule without echoing its value', function (string $locale, string $message): void {
    app()->setLocale($locale);
    app()->instance('env', 'production');
    Http::fake();
    Http::preventStrayRequests();
    $command = Artisan::all()['heptapod:user:create-owner'];
    $input = new ArrayInput(['--email' => 'owner@example.com', '--password-stdin' => true], $command->getDefinition());
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, "weak-password\n");
    rewind($stream);
    $input->setStream($stream);
    $output = new BufferedOutput;
    try {
        expect($command->run($input, $output))->toBe(1);
    } finally {
        fclose($stream);
    }
    expect($output->fetch())->toContain('Owner creation failed: '.$message)->not->toContain('weak-password');
    expect(User::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    ['en', 'The password field must contain at least one uppercase and one lowercase letter.'],
    ['de', 'Passwort muss mindestens einen Großbuchstaben und einen Kleinbuchstaben beinhalten.'],
]);

it('propagates unexpected missing reference role errors', function (): void {
    Role::query()->where('name', 'Owner')->delete();
    expect(fn () => Artisan::call('heptapod:user:create-owner', ['--email' => 'owner@example.com']))
        ->toThrow(LogicException::class, 'Owner role is missing');
});

it('runs non-interactively in production without requesting a compromised-password lookup for a generated password', function (): void {
    app()->instance('env', 'production');
    Http::fake();
    Http::preventStrayRequests();
    $status = Artisan::call('heptapod:user:create-owner', ['--email' => 'production@example.com', '--no-interaction' => true]);
    expect($status)->toBe(0, Artisan::output());
    expect(User::query()->sole()->hasVerifiedEmail())->toBeTrue();
    Http::assertNothingSent();
});

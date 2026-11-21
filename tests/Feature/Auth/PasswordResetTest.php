<?php

use Carbon\CarbonImmutable;
use Heptapod\Jobs\TerminateLiveConnections;
use Heptapod\Models\User;
use Heptapod\Notifications\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function (): void {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, PasswordReset::class);
});

test('reset password screen can be rendered', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, PasswordReset::class, function ($notification): true {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, PasswordReset::class, function ($notification) use ($user): true {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('a password reset ends every other session, API token and live connection of the account', function (): void {
    Notification::fake();
    Queue::fake([TerminateLiveConnections::class]);
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $apiToken = $user->createToken('before-reset', ['monitors:read'])->plainTextToken;
    // Signed in, but no request since: the sign-in itself binds the session.
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $otherBrowser = session()->all();

    // The reset happens in a second, signed-out browser.
    Auth::forgetGuards();
    session()->flush();
    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, PasswordReset::class, function (PasswordReset $notification) use ($user): true {
        $this->post(route('password.update'), ['token' => $notification->token, 'email' => $user->email,
            'password' => 'a-new-password', 'password_confirmation' => 'a-new-password'])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return true;
    });

    Auth::forgetGuards();
    session()->flush();
    session()->put($otherBrowser);
    $this->get(route('sites.index'))->assertRedirect(route('login'));
    $this->assertGuest();
    Auth::forgetGuards();
    $this->withToken($apiToken)->getJson('/api/monitors')->assertUnauthorized();
    expect(Queue::pushed(TerminateLiveConnections::class)->pluck('userId')->all())->toBe([$user->id]);
});

test('password cannot be reset with invalid token', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});

test('forgot password returns identical generic responses for existing and unknown emails', function (): void {
    Notification::fake();
    $htmlUser = User::factory()->create();
    $jsonUser = User::factory()->create();

    $existing = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $htmlUser->email]);
    $unknown = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'nobody@example.test']);

    $existing->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', trans(Password::RESET_LINK_SENT));
    $unknown->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', trans(Password::RESET_LINK_SENT));

    // Separate user so the JSON check exercises a real first request, not the broker cooldown.
    $existingJson = $this->postJson(route('password.email'), ['email' => $jsonUser->email]);
    $unknownJson = $this->postJson(route('password.email'), ['email' => 'still-nobody@example.test']);

    $existingJson->assertOk()->assertExactJson(['message' => trans(Password::RESET_LINK_SENT)]);
    $unknownJson->assertOk()->assertExactJson(['message' => trans(Password::RESET_LINK_SENT)]);
    Notification::assertSentToTimes($htmlUser, PasswordReset::class, 1);
    Notification::assertSentToTimes($jsonUser, PasswordReset::class, 1);
});

test('forgot password queues mail delivery off the request path so failing transports do not diverge responses', function (): void {
    config(['queue.default' => 'database']);
    $transportAttempts = 0;
    $failingTransport = new class($transportAttempts) extends AbstractTransport
    {
        public function __construct(private int &$attempts)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            $this->attempts++;
            throw new TransportException('Simulated SMTP outage');
        }

        public function __toString(): string
        {
            return 'failing-test-transport';
        }
    };
    Mail::mailer()->setSymfonyTransport($failingTransport);

    $user = User::factory()->create();

    $known = $this->postJson(route('password.email'), ['email' => $user->email]);
    $unknown = $this->postJson(route('password.email'), ['email' => 'absent@example.test']);

    $known->assertOk()->assertExactJson(['message' => trans(Password::RESET_LINK_SENT)]);
    $unknown->assertOk()->assertExactJson(['message' => trans(Password::RESET_LINK_SENT)]);
    expect($transportAttempts)->toBe(0);
    expect(DB::table('jobs')->where('queue', 'notifications')->count())->toBe(1);
});

test('reset password returns the same invalid token error for existing and unknown emails', function (): void {
    $user = User::factory()->create();

    $existing = $this->postJson(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertUnprocessable();

    $unknown = $this->postJson(route('password.update'), [
        'token' => 'invalid-token',
        'email' => 'nobody@example.test',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertUnprocessable();

    expect($existing->json('errors.email'))->toBe([trans(Password::INVALID_TOKEN)])
        ->and($unknown->json('errors.email'))->toBe([trans(Password::INVALID_TOKEN)]);
});

test('forgot password is rate-limited per email and per IP', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    Notification::fake();

    // Per email (3/min) across different IPs.
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$attempt}"])
            ->postJson(route('password.email'), ['email' => 'target@example.test'])
            ->assertOk();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->postJson(route('password.email'), ['email' => 'target@example.test'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');

    // Per IP (10/min) across distinct emails.
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson(route('password.email'), ['email' => "user{$attempt}@example.test"])
            ->assertOk();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->postJson(route('password.email'), ['email' => 'user11@example.test'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});

test('reset password submission is rate-limited per email and IP', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));

    // Per email+IP (5/min).
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->postJson(route('password.update'), [
                'token' => 'invalid-token',
                'email' => 'victim@example.test',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])->assertUnprocessable();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
        ->postJson(route('password.update'), [
            'token' => 'invalid-token',
            'email' => 'victim@example.test',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');

    // Per IP (10/min) across distinct emails (5 already used on 203.0.113.20).
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->postJson(route('password.update'), [
                'token' => 'invalid-token',
                'email' => "other{$attempt}@example.test",
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])->assertUnprocessable();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
        ->postJson(route('password.update'), [
            'token' => 'invalid-token',
            'email' => 'other6@example.test',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});

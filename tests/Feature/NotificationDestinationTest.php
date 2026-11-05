<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Testing\FakeSmtpTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

function webhookDestination(string $locale = 'en'): NotificationDestination
{
    $channel = app(ChannelRegistry::class)->get('webhook');

    return NotificationDestination::factory()->create([
        'label' => 'Operations', 'channel' => 'webhook', 'locale' => $locale,
        'config' => $channel->configSchema()->validateConfig(['url' => 'https://hooks.example/private-url', 'secret' => 'private-signing-secret']),
    ])->refresh();
}

it('round trips destination secrets with encrypted storage and secret-safe serialization and logs', function (): void {
    $destination = webhookDestination();
    $config = $destination->destinationConfig();
    expect($config->values())->toBe(['url' => 'https://hooks.example/private-url', 'secret' => 'private-signing-secret']);
    $row = DB::table('notification_destinations')->find($destination->id);
    expect($row->config)->not->toContain('private-url', 'private-signing-secret');
    expect($row->config_secrets)->not->toContain('private-url', 'private-signing-secret');
    expect($destination->toJson())->not->toContain('config_secrets', 'private-url', 'private-signing-secret');
    expect(json_encode($config, JSON_THROW_ON_ERROR))->not->toContain('private-url', 'private-signing-secret');
    expect(print_r($config, true))->not->toContain('private-url', 'private-signing-secret');
    expect(fn (): string => serialize($config))->toThrow(LogicException::class);
    $handler = new TestHandler;
    new Logger('notification', [$handler])->info('destination settings', ['destination' => $destination, 'config' => $config]);
    expect(json_encode($handler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain('private-url', 'private-signing-secret');
    $destination->update(['config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig(['url' => 'https://hooks.example/replaced', 'secret' => 'new-secret'])]);
    expect($destination->refresh()->destinationConfig()->values()['secret'])->toBe('new-secret');
    expect(fn () => $destination->update(['config' => ['secret' => 'unvalidated']]))->toThrow(InvalidArgumentException::class);
});

it('authorizes synchronous send-test for Owner and Admin and returns the actual provider result', function (string $role, string $locale): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $user->assignRole($role);
    $destination = webhookDestination($locale);
    Http::preventStrayRequests();
    Http::fake(['https://hooks.example/private-url' => Http::response()]);
    $this->actingAs($user)->postJson(route('notification-channels.test', $destination))
        ->assertOk()->assertExactJson(['status' => 'sent', 'reason' => null, 'providerMessageId' => null, 'diagnosis' => null]);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['is_test'] === true && $request['event_id'] === 0 && $request['destination_id'] === $destination->id
        && $request['title'] === ($locale === 'en' ? '[Test] Heptapod test notification' : '[Test] Heptapod-Testbenachrichtigung')
        && $request->header('X-Heptapod-Delivery') === ['0:'.$destination->id]);
    Http::assertSent(function (Request $request): bool {
        $timestamp = $request->header('X-Heptapod-Timestamp')[0] ?? '';
        expect($timestamp)->toBe((string) now()->timestamp);
        $signature = $request->header('Signature')[0];
        expect(hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->body(), 'private-signing-secret'), $signature))->toBeTrue()
            ->and(hash_equals(hash_hmac('sha256', $request->body(), 'private-signing-secret'), $signature))->toBeFalse();

        return true;
    });
})->with(['Owner', 'Admin'])->with(['en', 'de']);

it('records only observed synchronous acceptance of test mail', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    // Acceptance is observed only on a real transport; the array mailer counts as logged since 8e3a8e4e.
    config(['mail.default' => 'smtp']);
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $destination = NotificationDestination::factory()->create();

    $this->actingAs($user)->postJson(route('notification-channels.test', $destination))
        ->assertOk()->assertJsonPath('status', 'sent');

    expect($destination->refresh()->last_outcome)->toBe('accepted')
        ->and($destination->last_outcome_at?->toIso8601String())->toBe('2026-10-05T00:30:00+00:00');
    expect($transport->messages())->toHaveCount(1);
});

it('classifies real SMTP response exceptions by recipient versus configuration stage', function (string $command, int $code, string $stage, string $outcome): void {
    $owner = User::factory()->create();
    $owner->assignRole('Admin');
    $destination = NotificationDestination::factory()->create();
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport($command, $code));
    $this->actingAs($owner)->postJson(route('notification-channels.test', $destination))
        ->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('diagnosis', "SMTP {$code} {$stage} — SMTP response rejected");
    expect($destination->refresh()->last_outcome)->toBe($outcome);
})->with([
    'recipient 550' => ['RCPT TO:<ops@example.test>', 550, 'RCPT', 'rejected'],
    'message 554' => ['.', 554, 'DATA', 'rejected'],
    'temporary recipient failure' => ['RCPT TO:<ops@example.test>', 450, 'RCPT', 'failed'],
    'EHLO config' => ['EHLO hostname', 550, 'config', 'failed'],
    'STARTTLS config' => ['STARTTLS', 550, 'config', 'failed'],
    'MAIL FROM config' => ['MAIL FROM:<sender@example.test>', 550, 'config', 'failed'],
]);

it('creates and lists a team email channel without claiming mailbox verification', function (): void {
    $this->getJson(route('settings.notifications.team.data'))->assertUnauthorized();
    $member = User::factory()->create();
    $member->assignRole('Member');
    $mail = ['recipient_kind' => 'team', 'channel' => 'mail', 'label' => 'Operations', 'locale' => 'en'];
    $this->actingAs($member)->postJson(route('notification-channels.store'), [...$mail, 'config' => ['email' => 'ops@example.test']])->assertForbidden();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->postJson(route('notification-channels.store'), [...$mail, 'config' => ['email' => 'not an email']])
        ->assertUnprocessable()->assertJsonValidationErrors('values.email');
    $response = $this->postJson(route('notification-channels.store'), [...$mail, 'config' => ['email' => 'ops@example.test']])->assertCreated();
    $destination = NotificationDestination::query()->findOrFail($response->json('id'));
    expect($destination->destinationConfig()->string('email'))->toBe('ops@example.test');
    $channels = collect($this->getJson(route('settings.notifications.team.data'))->assertOk()->json('channels'))->keyBy('id');
    expect($channels[$destination->id])->toMatchArray(['address' => 'ops@example.test', 'label' => 'Operations', 'status' => 'active', 'last_outcome' => null]);
});

it('does not send for guests, members or missing IDs', function (): void {
    $destination = webhookDestination();
    Http::preventStrayRequests();
    Http::fake();
    $this->postJson(route('notification-channels.test', $destination))->assertUnauthorized();
    $user = User::factory()->create();
    $user->assignRole('Member');
    $this->actingAs($user)->postJson(route('notification-channels.test', $destination))->assertForbidden();
    $user->syncRoles('Owner');
    $this->postJson(route('notification-channels.test', 999999))->assertNotFound();
    Http::assertNothingSent();
});

it('allows a marked manual test for a disabled destination before re-enabling it', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $destination = webhookDestination();
    $destination->update(['enabled' => false]);
    Http::fake(['https://hooks.example/private-url' => Http::response()]);
    $this->actingAs($user)->postJson(route('notification-channels.test', $destination))->assertOk()->assertJsonPath('status', 'sent');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['is_test'] === true && $request['title'] === '[Test] Heptapod test notification');
    expect($destination->fresh()->enabled)->toBeFalse();
});

it('returns provider failure without exposing secrets in the send-test response', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $destination = webhookDestination();
    Http::fake(['https://hooks.example/private-url' => Http::response(['error' => 'private-signing-secret'], 500)]);
    $response = $this->actingAs($user)->postJson(route('notification-channels.test', $destination));
    $response->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('reason', 'notifications.transport_failed');
    expect($response->getContent())->not->toContain('private-url', 'private-signing-secret');
});

it('rejects malformed header credentials in send-test without exposing them in responses or logs', function (string $key, string $invalid): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $channel = app(ChannelRegistry::class)->get($key);
    $values = match ($key) {
        'ntfy' => ['server' => 'https://ntfy.example', 'topic' => 'private-topic', 'token' => 'valid-token'],
        default => ['channel' => '123', 'token' => 'valid-token'],
    };
    $destination = NotificationDestination::factory()->create([
        'channel' => $key, 'config' => $channel->configSchema()->validateConfig($values),
    ]);
    // Reproduce a legacy stored credential; the send boundary must revalidate it.
    $secret = 'private-token'.$invalid.'suffix';
    $destination->forceFill(['config_secrets' => ['token' => $secret, ...($key === 'ntfy' ? ['topic' => 'private-topic'] : [])]])->save();
    $handler = new TestHandler;
    $logger = Log::getLogger();
    $logger->pushHandler($handler);
    Http::preventStrayRequests();
    Http::fake();
    try {
        $response = $this->actingAs($user)->postJson(route('notification-channels.test', $destination));
        expect(json_encode($handler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain('private-token', 'suffix');
        expect($response->getContent())->not->toContain('private-token', 'suffix');
        $response->assertUnprocessable()->assertJsonValidationErrors('values.token');
        Http::assertNothingSent();
    } finally {
        $logger->popHandler();
    }
})->with(['ntfy', 'slack', 'discord'])->with(['LF' => "\n", 'CR' => "\r", 'NUL' => "\0", 'DEL' => "\x7f"]);

it('returns and logs useful diagnoses without URL or header credentials', function (bool $network): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $channel = app(ChannelRegistry::class)->get('ntfy');
    $token = 'header-token-'.bin2hex(random_bytes(16)).'/+=';
    $querySecret = 'query-secret-'.bin2hex(random_bytes(16)).'/+=';
    $password = 'url-password-'.bin2hex(random_bytes(16)).'/+=';
    $username = 'url-user-'.bin2hex(random_bytes(8));
    $topic = 'topic-'.bin2hex(random_bytes(16));
    $url = 'https://'.$username.':'.rawurlencode($password).'@ntfy.example?access_token='.rawurlencode($querySecret);
    $destination = NotificationDestination::factory()->create([
        'channel' => 'ntfy', 'config' => $channel->configSchema()->validateConfig(['server' => $url, 'topic' => $topic, 'token' => $token]),
    ]);
    Http::preventStrayRequests();
    Http::fake([$url => $network ? Http::failedConnection($url.' '.$token) : Http::response([
        'code' => 'topic_missing', 'error' => 'Unknown '.$topic.' '.$querySecret.' '.$password.' '.$username.' '.$token.' '.$url.' '.str_repeat('x', 300),
    ], 404)]);
    $handler = new TestHandler;
    $logger = Log::getLogger();
    $logger->pushHandler($handler);
    try {
        $response = $this->actingAs($user)->postJson(route('notification-channels.test', $destination));
        $response->assertOk()->assertJsonPath('status', 'failed');
        $diagnosis = $response->json('diagnosis');
        expect($diagnosis)->toBeString()->toContain($network ? 'ConnectionException' : 'HTTP 404');
        $records = $handler->getRecords();
        expect($records)->toHaveCount(1);
        expect($records[0]->level->getName())->toBe('WARNING');
        expect($records[0]->context['diagnosis'])->toBe($diagnosis);
        expect($records[0]->context['provider_error'])->toBeNull();
        foreach ([$diagnosis, $response->getContent(), json_encode($records, JSON_THROW_ON_ERROR)] as $output) {
            expect($output)->not->toContain($token, $querySecret, $password, $username, $topic, $url, rawurlencode($querySecret), rawurlencode($password), rawurlencode($token));
        }
        Http::assertSentCount(1);
    } finally {
        $logger->popHandler();
    }
})->with(['HTTP rejection' => false, 'network rejection' => true]);

it('limits send-test diagnoses to safe known provider fields', function (string $key, array $values, string|array $body, ?string $detail): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $channel = app(ChannelRegistry::class)->get($key);
    $destination = NotificationDestination::factory()->create([
        'channel' => $key, 'config' => $channel->configSchema()->validateConfig($values),
    ]);
    $url = match ($key) {
        'slack' => 'https://slack.com/api/chat.postMessage',
        'telegram' => 'https://api.telegram.org/bot'.$values['token'].'/sendMessage',
        'pushover' => 'https://api.pushover.net/1/messages.json',
        'discord' => 'https://discord.com/api/channels/'.$values['channel'].'/messages',
        'ntfy' => $values['server'],
        default => $values['url'],
    };
    Http::preventStrayRequests();
    Http::fake([str_ireplace('HTTPS://', 'https://', $url) => Http::response($body, 404)]);
    $handler = new TestHandler;
    $logger = Log::getLogger();
    $logger->pushHandler($handler);
    try {
        $response = $this->actingAs($user)->postJson(route('notification-channels.test', $destination));
        $response->assertOk()->assertJsonPath('status', 'failed');
        $diagnosis = 'HTTP 404 — RequestException'.($detail === null ? '' : ' — '.$detail);
        $response->assertJsonPath('diagnosis', $diagnosis);
        $records = $handler->getRecords();
        expect($records)->toHaveCount(1);
        expect($records[0]->level->getName())->toBe('WARNING');
        expect($records[0]->context['diagnosis'])->toBe($diagnosis);
        expect($records[0]->context['provider_error'])->toBe($detail);
        foreach ([$response->getContent(), json_encode($records, JSON_THROW_ON_ERROR)] as $output) {
            expect($output)->not->toContain('outside-field', 'private-token', 'PRIVATE-TOKEN', 'privateToken', '%FF', 'hooks.example');
        }
        Http::assertSentCount(1);
    } finally {
        $logger->popHandler();
    }
})->with([
    'Slack code only' => ['slack', ['channel' => '123', 'token' => 'private-token'], ['error' => 'invalid_token', 'raw' => 'outside-field private-token'], 'invalid_token'],
    'Slack mixed-case secret omitted' => ['slack', ['channel' => '123', 'token' => 'private-token'], ['error' => 'Rejected PRIVATE-TOKEN'], null],
    'secret beyond limit omitted' => ['slack', ['channel' => '123', 'token' => 'private-token'], ['error' => str_repeat('x', 121).'PRIVATE-TOKEN'], null],
    'field capped at 120' => ['slack', ['channel' => '123', 'token' => 'private-token'], ['error' => str_repeat('x', 200)], str_repeat('x', 120)],
    'raw body ignored' => ['slack', ['channel' => '123', 'token' => 'private-token'], 'outside-field private-token secret%2f%2b%3d', null],
    'URL field omitted' => ['slack', ['channel' => '123', 'token' => 'private-token'], ['error' => 'See HTTPS://hooks.example/privateToken'], null],
    'Telegram description' => ['telegram', ['chat_id' => '123', 'token' => 'private-token'], ['description' => 'Chat not found', 'raw' => 'outside-field'], 'Chat not found'],
    'Pushover errors' => ['pushover', ['user_key' => str_repeat('u', 30), 'token' => 'private-token'], ['errors' => ['Invalid user', 'PRIVATE-TOKEN']], 'Invalid user'],
    'Discord code and message' => ['discord', ['channel' => '123', 'token' => 'private-token'], ['code' => 50001, 'message' => 'Missing access', 'raw' => 'outside-field'], '50001; Missing access'],
    'ntfy error' => ['ntfy', ['server' => 'https://ntfy.example', 'topic' => 'private-token'], ['error' => 'Topic not found', 'raw' => 'outside-field'], 'Topic not found'],
    'Teams body ignored' => ['microsoftTeams', ['url' => 'HTTPS://hooks.example/privateToken'], ['error' => 'outside-field privateToken'], null],
    'uppercase webhook URL body ignored' => ['webhook', ['url' => 'HTTPS://user:password@hooks.example/privateToken?token=secret', 'secret' => 'private-token'], ['error' => 'outside-field privateToken user password secret'], null],
    'percent byte URL does not crash reporting' => ['webhook', ['url' => 'https://hooks.example/receiver?token=%FF', 'secret' => 'private-token'], ['error' => 'outside-field %FF'], null],
]);

it('enforces the core secret minimum on destination saves and permits explicit clearing', function (string $secret): void {
    $schema = app(ChannelRegistry::class)->get('webhook')->configSchema();
    try {
        NotificationDestination::factory()->create(['config' => $schema->validateConfig(['url' => 'https://hooks.example/endpoint', 'secret' => $secret])]);
        $this->fail('Short destination secret was persisted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['values.secret']);
    }
    expect(fn () => $schema->validateConfig(['url' => 'https://hooks.example/endpoint', 'secret' => '']))->toThrow(ValidationException::class);
    $optional = app(ChannelRegistry::class)->get('ntfy')->configSchema();
    $destination = NotificationDestination::factory()->create(['channel' => 'ntfy', 'config' => $optional->validateConfig(['server' => 'https://ntfy.example', 'topic' => 'private-topic', 'token' => ''])]);
    expect(array_key_exists('token', $destination->refresh()->config_secrets))->toBeFalse();
})->with(['a', 'ab', 'abc', '   ', '  ab  ']);

it('requires an explicit boolean opt-in for insecure webhook destinations', function (): void {
    $schema = app(ChannelRegistry::class)->get('webhook')->configSchema();
    expect(fn () => NotificationDestination::factory()->create(['channel' => 'webhook', 'config' => $schema->validateConfig(['url' => 'http://hooks.example/receiver', 'secret' => 'private-signing-secret'])]))->toThrow(ValidationException::class);
    expect(fn () => $schema->validateConfig(['url' => 'http://hooks.example/receiver', 'secret' => 'private-signing-secret', 'allow_insecure_http' => 'true']))->toThrow(ValidationException::class);
    $destination = NotificationDestination::factory()->create(['channel' => 'webhook', 'config' => $schema->validateConfig(['url' => 'http://hooks.example/receiver', 'secret' => 'private-signing-secret', 'allow_insecure_http' => true])]);
    expect($destination->refresh()->destinationConfig()->values()['allow_insecure_http'])->toBeTrue();
});

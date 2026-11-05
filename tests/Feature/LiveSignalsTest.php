<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\HealthStatus;
use Heptapod\Events\LiveSignal;
use Heptapod\Jobs\BroadcastLiveSignal;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Heptapod\SystemHealth\ReadSystemHealth;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('renders only public runtime Reverb settings in the application HTML', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'public-demo-key',
        'broadcasting.connections.reverb.secret' => 'private-demo-secret',
    ]);

    $response = $this->get('https://monitor.example.test:8443/login')->assertOk();
    $html = $response->getContent();
    expect(preg_match('/<meta name="heptapod-reverb" content="([^"]*)">/', $html, $matches))->toBe(1);
    $settings = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    expect($settings)->toBe([
        'key' => 'public-demo-key', 'host' => null, 'port' => null,
        'scheme' => null, 'path' => '',
    ]);
    expect($html)->not->toContain('private-demo-secret');

    config(['broadcasting.reverb_client' => ['host' => 'socket.example.test', 'port' => '9443', 'scheme' => 'https', 'path' => '/live']]);
    $html = $this->get('http://monitor.example.test/login')->assertOk()->getContent();
    expect(preg_match('/<meta name="heptapod-reverb" content="([^"]*)">/', $html, $matches))->toBe(1);
    expect(json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'key' => 'public-demo-key', 'host' => 'socket.example.test', 'port' => 9443,
        'scheme' => 'https', 'path' => '/live',
    ]);
    expect($html)->not->toContain('private-demo-secret');

    config(['broadcasting.connections.reverb.key' => null]);
    expect($this->get('/login')->assertOk()->getContent())->not->toContain('name="heptapod-reverb"');
});

it('authorizes only the assigned client channels and keeps restricted members off instance', function (): void {
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => 'test-key',
        'broadcasting.connections.pusher.secret' => 'test-secret', 'broadcasting.connections.pusher.app_id' => 'test-app']);
    require base_path('routes/channels.php');
    Event::fake([LiveSignal::class]);
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    expect($member->hasRole('Member'))->toBeTrue();
    expect($member->canAccessClient($client->id))->toBeTrue();

    $this->actingAs($member)->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'presence-client.'.$client->id])->assertOk();
    $this->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'presence-client.'.$other->id])->assertForbidden();
    $this->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'presence-instance'])->assertForbidden();
    $this->get('/sites')->assertInertia(fn ($page) => $page->where('liveChannels', ['client.'.$client->id]));

    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'presence-instance'])->assertOk();
});

it('emits only site identity after commit and never the changed name', function (): void {
    $check = Check::factory()->create();
    $site = $check->site;
    Event::fake([LiveSignal::class]);

    DB::commit();
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
    try {
        DB::beginTransaction();
        $site->update(['friendly_name' => 'Do not broadcast this name']);
        DB::rollBack();
        Event::assertNotDispatched(LiveSignal::class);

        DB::beginTransaction();
        $site->refresh()->update(['friendly_name' => 'Do not broadcast this name']);
        DB::commit();
        Event::assertDispatched(LiveSignal::class, function (LiveSignal $signal) use ($site): bool {
            expect($signal->topic)->toBe('state.changed');
            expect($signal->broadcastOn())->toHaveCount(2);
            expect(array_map(static fn (PresenceChannel $channel): string => $channel->name, $signal->broadcastOn()))->toEqualCanonicalizing(['presence-instance', 'presence-client.'.$site->client_id]);
            expect($signal->broadcastWith())->toHaveKeys(['topic', 'ids', 'at']);
            expect(json_encode($signal->broadcastWith()))->not->toContain('Do not broadcast this name');

            return $signal->ids === ['client' => $site->client_id, 'site' => $site->id];
        });
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('checks')->where('id', $check->id)->delete();
        DB::table('sites')->where('id', $site->id)->delete();
        DB::table('clients')->where('id', $site->client_id)->delete();
        DB::connection()->setTransactionManager(app('db.transactions'));
        DB::beginTransaction();
    }
});

it('dispatches claimed runs when broadcast enqueue is unavailable', function (): void {
    config(['queue.connections.redis-broadcasts' => null]);
    Queue::fake([RunCheck::class]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create();
    $clientId = $check->site->client_id;

    DB::commit();
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
    try {
        $logger = Log::getLogger();
        if (! $logger instanceof Logger) {
            throw new LogicException('Expected the configured Monolog logger.');
        }
        $records = new TestHandler;
        $logger->pushHandler($records);
        try {
            $this->actingAs($owner)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();
            Queue::assertPushed(RunCheck::class, fn (RunCheck $job): bool => $job->checkId === $check->id);
            expect($records->getRecords())->toHaveCount(1);
            expect($records->hasErrorThatContains('redis-broadcasts'))->toBeTrue();
        } finally {
            $logger->popHandler();
        }
    } finally {
        DB::table('checks')->where('id', $check->id)->delete();
        DB::table('sites')->where('id', $check->site_id)->delete();
        DB::table('clients')->where('id', $clientId)->delete();
        DB::table('users')->where('id', $owner->id)->delete();
        DB::connection()->setTransactionManager(app('db.transactions'));
        DB::beginTransaction();
    }
});

it('finishes a claimed run and reports a missing Reverb configuration once', function (): void {
    config(['broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => null,
        'broadcasting.connections.reverb.secret' => null,
        'broadcasting.connections.reverb.app_id' => null]);
    app(BroadcastManager::class)->purge('reverb');
    RateLimiter::clear('live:reverb:configuration');
    Queue::fake([RunCheck::class, BroadcastLiveSignal::class]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create();
    $clientId = $check->site->client_id;
    $logger = Log::getLogger();
    if (! $logger instanceof Logger) {
        throw new LogicException('Expected the configured Monolog logger.');
    }
    $records = new TestHandler;
    $logger->pushHandler($records);

    DB::commit();
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
    try {
        $this->actingAs($owner)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();
        Queue::assertPushed(RunCheck::class, fn (RunCheck $job): bool => $job->checkId === $check->id);
        Queue::assertPushed(BroadcastLiveSignal::class, fn (BroadcastLiveSignal $job): bool => $job->signal->topic === 'run.queued');
        $job = Queue::pushed(BroadcastLiveSignal::class)[0];
        $job->handle(app(BroadcastManager::class));
        $job->handle(app(BroadcastManager::class));
        expect($records->getRecords())->toHaveCount(1)
            ->and($records->hasErrorThatContains('Reverb app credentials'))->toBeTrue()
            ->and(app(ReadSystemHealth::class)()->reverb->status)->toBe(HealthStatus::NotConfigured)
            ->and(app(ReadSystemHealth::class)()->reverb->error)->toContain('REVERB_APP_KEY');
    } finally {
        $logger->popHandler();
        RateLimiter::clear('live:reverb:configuration');
        DB::table('checks')->where('id', $check->id)->delete();
        DB::table('sites')->where('id', $check->site_id)->delete();
        DB::table('clients')->where('id', $clientId)->delete();
        DB::table('users')->where('id', $owner->id)->delete();
        DB::connection()->setTransactionManager(app('db.transactions'));
        DB::beginTransaction();
    }
});

it('reports an unreachable Reverb once without retaining disposable broadcast failures', function (): void {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => 1, 'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false]);
    $transport = new MockHandler([
        new ConnectException('Reverb offline', new Request('POST', 'http://127.0.0.1:1/apps/test-app/events')),
        new ConnectException('Reverb offline', new Request('POST', 'http://127.0.0.1:1/apps/test-app/events')),
    ]);
    config(['broadcasting.connections.reverb.client_options.handler' => HandlerStack::create($transport)]);
    app(BroadcastManager::class)->purge('reverb');
    RateLimiter::clear('live:reverb:transport');
    $logger = Log::getLogger();
    if (! $logger instanceof Logger) {
        throw new LogicException('Expected the configured Monolog logger.');
    }
    $records = new TestHandler;
    $logger->pushHandler($records);
    $failedBefore = DB::table('failed_jobs')->count();
    try {
        $signal = new LiveSignal('state.changed', ['client' => 1], now()->toIso8601String(), 1);
        Bus::dispatchSync(new BroadcastLiveSignal($signal));
        Bus::dispatchSync(new BroadcastLiveSignal($signal));
        expect($records->getRecords())->toHaveCount(1)
            ->and(DB::table('failed_jobs')->count())->toBe($failedBefore)
            ->and($transport->count())->toBe(0);

        config(['broadcasting.connections.reverb.driver' => 'unsupported']);
        app(BroadcastManager::class)->purge('reverb');
        expect(fn (): mixed => Bus::dispatchSync(new BroadcastLiveSignal($signal)))->toThrow(InvalidArgumentException::class);
    } finally {
        $logger->popHandler();
        RateLimiter::clear('live:reverb:transport');
    }
});

it('broadcasts a queued run without leaking site config', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create();
    $check->site->update(['friendly_name' => 'Sensitive monitor label']);
    Event::fake([LiveSignal::class]);
    Queue::fake([RunCheck::class]);

    $this->actingAs($user)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();

    Event::assertDispatched(LiveSignal::class, function (LiveSignal $signal) use ($check, $user): bool {
        if ($signal->topic !== 'run.queued') {
            return false;
        }
        expect($signal->broadcastWith()['run'])->toMatchArray(['check_id' => $check->id, 'phase' => 'queued',
            'queue_position' => 1, 'progress_n' => null, 'progress_total' => null, 'started_at' => null, 'actor' => $user->name]);
        expect(json_encode($signal->broadcastWith()))->not->toContain('Sensitive monitor label');

        return $signal->ids === ['site' => $check->site_id, 'check' => $check->id];
    });
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Enums\RecipientKind;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-15T09:00:00Z'));
    $this->withoutVite();
});

it('stores one canonical form for HTTPS, SSH, scp and ssh:// repository addresses through the API', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();

    $this->withToken($owner->createToken('write', ['*'])->plainTextToken)->putJson('/api/monitors/'.$site->id, ['repositories' => [
        ['url' => 'https://GitHub.com/Acme/Shop.git/', 'label' => 'Shop'],
        ['url' => 'git@gitlab.com:acme/backend.git', 'label' => ''],
        ['url' => 'ssh://git@git.example.com:2222/group/sub/infra.git'],
        ['url' => 'http://git.example.com:8443/acme/docs/'],
    ]])->assertOk()->assertJsonPath('repositories', [
        ['url' => 'https://github.com/Acme/Shop', 'label' => 'Shop'],
        ['url' => 'https://gitlab.com/acme/backend', 'label' => null],
        ['url' => 'https://git.example.com/group/sub/infra', 'label' => null],
        ['url' => 'https://git.example.com:8443/acme/docs', 'label' => null],
    ]);

    $this->putJson('/api/monitors/'.$site->id, ['friendly_name' => 'Kept'])->assertOk()->assertJsonCount(4, 'repositories');
    $this->putJson('/api/monitors/'.$site->id, ['repositories' => []])->assertOk()->assertJsonPath('repositories', []);
});

it('refuses credentials, non-repository addresses, repeats and more than ten links and stores nothing', function (array $repositories, string $field, string $message): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $site->repositories()->create(['url' => 'https://github.com/acme/kept', 'position' => 0]);

    $this->withToken($owner->createToken('write', ['*'])->plainTextToken)->putJson('/api/monitors/'.$site->id, ['repositories' => $repositories])
        ->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);

    expect($site->repositories()->pluck('url')->all())->toBe(['https://github.com/acme/kept']);
})->with([
    'token in an HTTPS address' => [[['url' => 'https://ghp_secret@github.com/acme/shop']], 'repositories.0.url', 'Remove the credentials'],
    'user and password' => [[['url' => 'https://user:pass@git.example.com/acme/shop']], 'repositories.0.url', 'Remove the credentials'],
    'SSH password' => [[['url' => 'ssh://git:secret@github.com/acme/shop']], 'repositories.0.url', 'Remove the credentials'],
    'host without a repository path' => [[['url' => 'https://github.com/']], 'repositories.0.url', 'Enter a repository address'],
    'not an address' => [[['url' => 'acme/shop']], 'repositories.0.url', 'Enter a repository address'],
    'same repository in another form' => [[['url' => 'https://github.com/acme/shop'], ['url' => 'git@GITHUB.com:acme/shop.git']], 'repositories.1.url', 'This repository is already linked.'],
    'eleven links' => [array_map(static fn (int $n): array => ['url' => 'https://github.com/acme/repo-'.$n], range(1, 11)), 'repositories', 'must not have more than 10 items'],
]);

it('finds every visible monitor of a repository by any remote form, within the token client scope', function (): void {
    $owner = User::factory()->owner()->create();
    [$agency, $other] = Client::factory()->count(2)->create();
    $shop = Site::factory()->for($agency)->create();
    $mirror = Site::factory()->for($other)->create();
    $unrelated = Site::factory()->for($agency)->create();
    $shop->repositories()->create(['url' => 'https://github.com/acme/shop', 'position' => 0]);
    $mirror->repositories()->create(['url' => 'https://github.com/acme/shop', 'position' => 0]);
    $unrelated->repositories()->create(['url' => 'https://github.com/acme/shop-legacy', 'position' => 0]);
    $query = '/api/monitors?filter[repository_url]='.rawurlencode('git@github.com:acme/shop.git');

    $this->withToken($owner->createToken('all', ['monitors:read'])->plainTextToken)
        ->getJson($query)->assertOk()->assertJsonPath('data.*.id', [$shop->id, $mirror->id]);

    $scoped = $owner->createToken('agency', ['monitors:read']);
    $scoped->accessToken->forceFill(['client_id' => $agency->id])->save();
    $this->withToken($scoped->plainTextToken)->getJson($query)->assertOk()->assertJsonPath('data.*.id', [$shop->id]);

    $this->getJson('/api/monitors?filter[repository_url]='.rawurlencode('https://token@github.com/acme/shop'))
        ->assertUnprocessable()->assertJsonValidationErrors(['filter.repository_url' => 'Remove the credentials']);
});

it('lets Owners and Admins edit repositories in Site settings with an audit entry and shows them read-only to Members', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();

    $this->actingAs($owner)->from('/sites/'.$site->id.'/settings')->patch('/sites/'.$site->id, ['repositories' => [
        ['url' => 'git@github.com:acme/shop.git', 'label' => 'Shop'],
        ['url' => 'https://github.com/acme/infra', 'label' => ''],
    ]])->assertSessionHasNoErrors();
    $this->get('/sites/'.$site->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page->where('repositories', [
        ['url' => 'https://github.com/acme/shop', 'label' => 'Shop'],
        ['url' => 'https://github.com/acme/infra', 'label' => null],
    ]));
    $audit = Activity::query()->where('event', 'site.repositories.updated')->sole();
    expect([$audit->subject_id, $audit->causer_id, $audit->properties->toArray()])->toBe([$site->id, $owner->id, [
        'old' => ['repositories' => []],
        'attributes' => ['repositories' => [['url' => 'https://github.com/acme/shop', 'label' => 'Shop'], ['url' => 'https://github.com/acme/infra', 'label' => null]]],
    ]]);

    // Saving the page again with the stored rows changes nothing and writes no second audit entry.
    $this->patch('/sites/'.$site->id, ['repositories' => [['url' => 'https://github.com/acme/shop', 'label' => 'Shop'], ['url' => 'https://github.com/acme/infra', 'label' => null]]])
        ->assertSessionHasNoErrors();
    expect(Activity::query()->where('event', 'site.repositories.updated')->count())->toBe(1);

    $member = User::factory()->member()->create();
    $this->actingAs($member)->patch('/sites/'.$site->id, ['repositories' => []])->assertForbidden();
    $this->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page->where('repositories', [
        ['url' => 'https://github.com/acme/shop', 'label' => 'Shop'],
        ['url' => 'https://github.com/acme/infra', 'label' => null],
    ]));
    expect($site->repositories()->count())->toBe(2);
});

it('sends repository links to team and personal webhooks and never to a client webhook', function (RecipientKind $kind, bool $included): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.example.test/*' => Http::response('', 200)]);
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $site->repositories()->create(['url' => 'https://github.com/acme/shop', 'label' => 'Shop', 'position' => 0]);
    $channel = NotificationDestination::factory()->create([
        'recipient_kind' => $kind, 'channel' => 'webhook',
        'user_id' => $kind === RecipientKind::User ? User::factory()->owner()->create()->id : null,
        'client_id' => $kind === RecipientKind::Client ? $client->id : null,
        'config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig(['url' => 'https://receiver.example.test/hook', 'secret' => 'webhook-secret']),
    ]);
    $event = Event::factory()->create(['site_id' => $site->id, 'check_id' => Check::factory()->for($site)->create(['type' => CheckKey::Performance])->id,
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    app()->call(new SendNotification($delivery->id)->handle(...));

    expect(Http::recorded()[0][0]->data()['repositories'])->toBe($included ? [['url' => 'https://github.com/acme/shop', 'label' => 'Shop']] : []);
})->with([
    'team channel' => [RecipientKind::Team, true],
    'personal channel' => [RecipientKind::User, true],
    'client channel' => [RecipientKind::Client, false],
]);

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('offers incoming webhooks in channel settings and sends the provider payload', function (string $channel, string $url, string $recipient, int $status): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
    $this->actingAs(User::factory()->owner()->create(['locale' => 'en']));
    $client = Client::factory()->create(['locale' => 'en']);
    foreach (['settings.notifications.team.data', 'settings.notifications.data', 'clients.notifications.data'] as $page) {
        $types = $this->getJson(route($page, $page === 'clients.notifications.data' ? $client : []))->assertOk()->json('channel_types');
        expect(collect($types)->pluck('key'))->toContain($channel);
    }
    $id = $this->postJson(route('notification-channels.store'), [
        'channel' => $channel, 'label' => 'Alert receiver', 'recipient_kind' => $recipient, 'config' => ['url' => $url],
        ...match ($recipient) {
            'team' => ['locale' => 'en'], 'client' => ['client_id' => $client->id], default => []
        },
    ])->assertCreated()->json('id');

    Http::preventStrayRequests();
    Http::fake([$url => Http::response('', $status)]);
    $this->postJson(route('notification-channels.test', $id))->assertOk()->assertJsonPath('status', 'sent');
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($channel, $url): bool {
        $title = '[Test] Heptapod test notification';
        $link = route('sites.index');
        expect($request->method())->toBe('POST')->and($request->url())->toBe($url);
        $payload = $request->data();
        match ($channel) {
            'slackWebhook' => expect($payload['text'])->toStartWith($title."\nYour channel is working.")->toEndWith($link),
            'discordWebhook' => expect($payload['embeds'][0])->toMatchArray(['title' => $title, 'url' => $link, 'timestamp' => '2026-11-18T12:00:00+00:00'])
                ->and($payload['embeds'][0]['description'])->toStartWith('Your channel is working.')
                ->and($payload['allowed_mentions']['parse'])->toBeEmpty(),
            'googleChat' => expect($payload['cardsV2'][0]['card']['header']['title'])->toBe($title)
                ->and($payload['cardsV2'][0]['card']['sections'][0]['widgets'][0]['textParagraph']['text'])->toStartWith('Your channel is working.')
                ->and($payload['cardsV2'][0]['card']['sections'][0]['widgets'][1]['buttonList']['buttons'][0]['onClick']['openLink']['url'])->toBe($link),
        };

        return true;
    });
})->with([
    'Slack team' => ['slackWebhook', 'https://mattermost.example.test/hooks/sample-token', 'team', 200],
    'Discord personal' => ['discordWebhook', 'https://discord.com/api/webhooks/123456789/example-token', 'user', 204],
    'Discord PTB URL' => ['discordWebhook', 'https://ptb.discord.com/api/webhooks/123456789/example-token', 'user', 204],
    'Discord Canary URL' => ['discordWebhook', 'https://canary.discord.com/api/webhooks/123456789/example-token', 'user', 204],
    'Google Chat client' => ['googleChat', 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=example-key&token=example-token', 'client', 200],
]);

it('rejects URLs outside the incoming webhook provider format', function (string $channel, string $url): void {
    $this->actingAs(User::factory()->owner()->create())->postJson(route('notification-channels.store'), [
        'channel' => $channel, 'label' => 'Alerts', 'recipient_kind' => 'team', 'locale' => 'en', 'config' => ['url' => $url],
    ])->assertUnprocessable()->assertJsonValidationErrors('values.url');
})->with([
    'Slack needs HTTPS' => ['slackWebhook', 'http://mattermost.example.test/hooks/sample-token'],
    'Discord host' => ['discordWebhook', 'https://discord.example.test/api/webhooks/123456789/example-token'],
    'Discord endpoint' => ['discordWebhook', 'https://discord.com/api/channels/123456789/messages'],
    'Chat host' => ['googleChat', 'https://chat.example.test/v1/spaces/AAA/messages?key=example-key&token=example-token'],
    'Chat credentials' => ['googleChat', 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=example-key'],
]);

it('refuses a private target before attempting a Slack-compatible webhook delivery', function (): void {
    $this->actingAs(User::factory()->owner()->create());
    $id = $this->postJson(route('notification-channels.store'), [
        'channel' => 'slackWebhook', 'label' => 'Slack-compatible receiver', 'recipient_kind' => 'team', 'locale' => 'en',
        'config' => ['url' => 'https://127.0.0.1/hooks/sample-token'],
    ])->assertCreated()->json('id');
    $this->postJson(route('notification-channels.test', $id))->assertOk()
        ->assertJsonPath('status', 'failed')->assertJsonPath('reason', 'notifications.destination_refused');
    Http::assertNothingSent();
});

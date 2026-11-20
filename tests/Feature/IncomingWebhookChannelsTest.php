<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
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
            'slackWebhook' => expect($payload)->not->toHaveKey('channel')
                ->and($payload['unfurl_links'])->toBeFalse()
                ->and($payload['unfurl_media'])->toBeFalse()
                ->and($payload['text'])->toStartWith($title."\nYour channel is working.")->toEndWith('<'.$link.'|View details>')
                ->and($payload['blocks'][0])->toBe(['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $title]])
                ->and($payload['blocks'][1]['type'])->toBe('section')
                ->and($payload['blocks'][1]['text'])->toBe(['type' => 'mrkdwn', 'text' => 'Your channel is working.', 'verbatim' => true])
                ->and($payload['blocks'][2]['text'])->toBe(['type' => 'mrkdwn', 'text' => '<'.$link.'|View details>', 'verbatim' => true])
                ->and($payload['blocks'][3]['type'])->toBe('context'),
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

it('sends Block Kit with clickable links, Mattermost-compatible fallback text and localized durations for Slack channels', function (string $channel, string $locale, string $expectedDuration, string $durationField, string $openLabel): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T19:51:20Z'));
    $settings = app(InstanceSettings::class);
    $settings->name = 'Heptapod Monitor';
    $settings->timezone = 'Europe/Berlin';
    $settings->save();

    $client = Client::factory()->create(['locale' => $locale]);
    $site = Site::factory()->for($client)->create(['friendly_name' => 'shop.example.test', 'url' => 'https://shop.example.test']);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Lighthouse]);
    $incident = Incident::factory()->for($check)->create([
        'site_id' => $site->id,
        'opened_at' => CarbonImmutable::parse('2026-11-19T19:49:00Z'),
        'first_failure_at' => CarbonImmutable::parse('2026-11-19T19:49:00Z'),
        'resolved_at' => now(),
    ]);
    $opener = Event::factory()->create([
        'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
        'occurred_at' => $incident->opened_at,
    ]);
    $resolved = Event::factory()->create([
        'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.resolved', 'cause' => 'failed', 'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded,
        'payload' => ['summary' => 'lighthouse.load_timed_out', 'summary_params' => []],
    ]);

    $url = $channel === 'slack' ? 'https://slack.com/api/chat.postMessage' : 'https://mattermost.example.test/hooks/sample-token';
    $rawConfig = $channel === 'slack' ? ['channel' => 'C01234567', 'token' => 'xoxb-example'] : ['url' => $url];
    $destination = NotificationDestination::factory()->create([
        'channel' => $channel, 'locale' => $locale,
        'config' => app(ChannelRegistry::class)->get($channel)->configSchema()->validateConfig($rawConfig),
    ]);
    Delivery::query()->create(['event_id' => $opener->id, 'destination_id' => $destination->id, 'state' => DeliveryState::Sent,
        'attempts' => 1, 'sent_at' => $opener->occurred_at, 'next_attempt_at' => $opener->occurred_at, 'suppression_evaluated_at' => $opener->occurred_at]);
    $delivery = Delivery::query()->create(['event_id' => $resolved->id, 'destination_id' => $destination->id,
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    Http::preventStrayRequests();
    Http::fake([$url => Http::response($channel === 'slack' ? ['ok' => true, 'ts' => '1.2'] : 'ok', 200)]);

    app()->call(new SendNotification($delivery->id)->handle(...));

    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    $incidentUrl = route('incidents.show', ['incident' => $incident->id]);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($channel, $url, $incidentUrl, $expectedDuration, $durationField, $openLabel): bool {
        expect($request->url())->toBe($url);
        $payload = $request->data();
        $siteLink = '<https://shop.example.test|shop.example.test>';
        $incidentLink = '<'.$incidentUrl.'|'.$openLabel.'>';

        expect($payload['blocks'][0]['type'])->toBe('header')
            ->and($payload['blocks'][0]['text']['type'])->toBe('plain_text')
            ->and($payload['blocks'][1]['type'])->toBe('section')
            ->and($payload['blocks'][1]['text']['type'])->toBe('mrkdwn')
            ->and($payload['blocks'][1]['text']['verbatim'])->toBeTrue()
            ->and(collect($payload['blocks'][1]['fields'])->pluck('text')->all())->toContain(
                '*'.($durationField === 'Total duration' ? 'Site' : 'Website')."*\n".$siteLink,
                '*'.$durationField."*\n".$expectedDuration,
            )
            ->and($payload['blocks'][2]['text'])->toBe(['type' => 'mrkdwn', 'text' => $incidentLink, 'verbatim' => true])
            ->and($payload['blocks'][3])->toBe(['type' => 'context', 'elements' => [['type' => 'plain_text', 'text' => 'Heptapod Monitor']]])
            ->and($payload['text'])->toContain($siteLink, $expectedDuration, $incidentLink)
            ->and($payload['text'])->not->toContain('86540')
            ->and($payload['mrkdwn'] ?? true)->toBeTrue()
            ->and($payload['unfurl_links'])->toBeFalse()
            ->and($payload['unfurl_media'])->toBeFalse();

        if ($channel === 'slack') {
            expect($payload['channel'])->toBe('C01234567')->and($payload['parse'])->toBe('none');
        } else {
            expect($payload)->not->toHaveKey('channel');
        }

        return true;
    });
})->with([
    'Slack API EN' => ['slack', 'en', '1 day 2 min', 'Total duration', 'Open incident'],
    'Slack API DE' => ['slack', 'de', '1 Tag 2 Min.', 'Gesamtdauer', 'Störung öffnen'],
    'Slack webhook EN' => ['slackWebhook', 'en', '1 day 2 min', 'Total duration', 'Open incident'],
    'Slack webhook DE' => ['slackWebhook', 'de', '1 Tag 2 Min.', 'Gesamtdauer', 'Störung öffnen'],
]);

it('bounds oversized Slack webhook content within Block Kit limits without breaking UTF-8, entities or links', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
    $client = Client::factory()->create(['locale' => 'en']);
    $site = Site::factory()->for($client)->create(['friendly_name' => 'long.example.test', 'url' => 'https://long.example.test/status?a=1&b=2']);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $incident = Incident::factory()->for($check)->create([
        'site_id' => $site->id,
        'opened_at' => now(),
        'first_failure_at' => now(),
        'evidence' => ['summary' => 'causes.error', 'summary_params' => ['message' => str_repeat('🚨 & <https://evil.test|T> ', 400)]],
    ]);
    $event = Event::factory()->create([
        'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
        'payload' => ['summary' => 'causes.error', 'summary_params' => ['message' => str_repeat('🚨 & <https://evil.test|T> ', 400)]],
    ]);
    $url = 'https://hooks.slack.example.test/services/T000/B000/XXX';
    $destination = NotificationDestination::factory()->create([
        'channel' => 'slackWebhook', 'locale' => 'en',
        'config' => app(ChannelRegistry::class)->get('slackWebhook')->configSchema()->validateConfig(['url' => $url]),
    ]);
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id,
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    Http::preventStrayRequests();
    Http::fake([$url => Http::response('ok', 200)]);

    app()->call(new SendNotification($delivery->id)->handle(...));

    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    $incidentUrl = route('incidents.show', ['incident' => $incident->id]);
    Http::assertSent(function (Request $request) use ($incidentUrl): bool {
        $payload = $request->data();
        $headerText = $payload['blocks'][0]['text']['text'];
        $sectionText = $payload['blocks'][1]['text']['text'];
        $fields = collect($payload['blocks'][1]['fields'])->pluck('text')->all();

        expect(strlen($headerText))->toBeLessThanOrEqual(150)
            ->and(strlen($sectionText))->toBeLessThanOrEqual(3000)
            ->and(strlen($payload['text']))->toBeLessThanOrEqual(3000)
            ->and($payload['mrkdwn'] ?? true)->toBeTrue()
            ->and(mb_check_encoding($headerText.$sectionText.$payload['text'], 'UTF-8'))->toBeTrue()
            ->and($sectionText)->toContain('shortened')->not->toContain('<https://evil.test|T>')
            ->and($payload['text'])->toContain('shortened')->not->toContain('<https://evil.test|T>')
            ->and($payload['text'])->toEndWith('<'.$incidentUrl.'|Open incident>')
            ->and($payload['blocks'][2]['text']['text'])->toBe('<'.$incidentUrl.'|Open incident>')
            ->and(implode("\n", $fields))->toContain('<https://long.example.test/status?[redacted]|long.example.test/status>');
        foreach ($fields as $field) {
            expect(strlen($field))->toBeLessThanOrEqual(2000);
        }

        return true;
    });
});

it('keeps 2048-byte site URLs and URLs with Markdown delimiters clickable in both Block Kit and Mattermost fallback', function (string $siteUrl, string $expectedEncodedUrl, bool $overflowsField): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
    $client = Client::factory()->create(['locale' => 'en']);
    $site = Site::factory()->for($client)->create(['friendly_name' => 'edge.example.test', 'url' => $siteUrl]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $incident = Incident::factory()->for($check)->create([
        'site_id' => $site->id, 'opened_at' => now(), 'first_failure_at' => now(),
        'evidence' => ['summary' => 'causes.error', 'summary_params' => ['message' => str_repeat('Timeout waiting for upstream. ', 40)]],
    ]);
    $event = Event::factory()->create([
        'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
        'payload' => ['summary' => 'causes.error', 'summary_params' => ['message' => str_repeat('Timeout waiting for upstream. ', 40)]],
    ]);
    $webhookUrl = 'https://mattermost.example.test/hooks/sample-token';
    $destination = NotificationDestination::factory()->create([
        'channel' => 'slackWebhook', 'locale' => 'en',
        'config' => app(ChannelRegistry::class)->get('slackWebhook')->configSchema()->validateConfig(['url' => $webhookUrl]),
    ]);
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id,
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    Http::preventStrayRequests();
    Http::fake([$webhookUrl => Http::response('ok', 200)]);

    app()->call(new SendNotification($delivery->id)->handle(...));

    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    $incidentUrl = route('incidents.show', ['incident' => $incident->id]);
    Http::assertSent(function (Request $request) use ($expectedEncodedUrl, $incidentUrl, $overflowsField): bool {
        $payload = $request->data();
        expect($payload['mrkdwn'] ?? true)->toBeTrue()
            ->and(strlen($payload['text']))->toBeLessThanOrEqual(3000)
            ->and($payload['text'])->toContain('<'.$expectedEncodedUrl.'|')
            ->and($payload['text'])->toEndWith('<'.$incidentUrl.'|Open incident>');

        // Every `<url|label>` in fallback text must translate cleanly under Mattermost's `<url|label>` -> `[label](url)` rewrite.
        preg_match_all('/<([^<>]+)>/', $payload['text'], $matches);
        $destinations = [];
        foreach ($matches[1] as $inner) {
            expect(substr_count($inner, '|'))->toBe(1);
            [$dest, $label] = explode('|', $inner, 2);
            expect($dest)->not->toContain(')', '(', ' ', '\\')
                ->and($label)->not->toContain('|', '[', ']');
            $destinations[] = $dest;
        }
        expect($destinations)->toContain($expectedEncodedUrl, $incidentUrl);

        foreach ($payload['blocks'][1]['fields'] as $field) {
            expect(strlen($field['text']))->toBeLessThanOrEqual(2000);
        }
        if ($overflowsField) {
            expect($payload['blocks'][2]['type'])->toBe('section')
                ->and($payload['blocks'][2]['text']['type'])->toBe('mrkdwn')
                ->and($payload['blocks'][2]['text']['verbatim'])->toBeTrue()
                ->and(strlen($payload['blocks'][2]['text']['text']))->toBeLessThanOrEqual(3000)
                ->and($payload['blocks'][2]['text']['text'])->toContain('<'.$expectedEncodedUrl.'|')
                ->and($payload['blocks'][3]['text']['text'])->toBe('<'.$incidentUrl.'|Open incident>');
        } else {
            expect(collect($payload['blocks'][1]['fields'])->pluck('text')->implode("\n"))->toContain('<'.$expectedEncodedUrl.'|');
        }

        return true;
    });
})->with([
    '2021-byte URL spills to section' => [
        'https://edge.example.test/'.str_repeat('a', 1995),
        'https://edge.example.test/'.str_repeat('a', 1995),
        true,
    ],
    'URL with ) and |' => [
        'https://edge.example.test/status)live|check',
        'https://edge.example.test/status%29live%7Ccheck',
        false,
    ],
]);

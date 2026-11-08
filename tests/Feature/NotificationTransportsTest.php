<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\AlertMessage;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\ChannelType;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

require_once __DIR__.'/../Support/NotificationChannels.php';

// Provider outcomes, not the local log/array mailer: phpunit's array mailer now records mail as logged (8e3a8e4e).
beforeEach(function (): void {
    config(['mail.default' => 'smtp']);
});

/** @return array<string, array{array<string, string>, string, array<string, mixed>|string}> */
function notificationTransports(): array
{
    return [
        'mail' => [['email' => 'operator@example.com'], '', []],
        'slack' => [['channel' => 'C123', 'token' => 'private-token'], 'https://slack.com/api/chat.postMessage', ['ok' => true, 'ts' => '123.456']],
        'discord' => [['channel' => '123', 'token' => 'private-token'], 'https://discord.com/api/channels/123/messages', ['id' => '321']],
        'microsoftTeams' => [['url' => 'https://teams.example/private-token'], 'https://teams.example/private-token', ''],
        'telegram' => [['chat_id' => '123', 'token' => 'private-token'], 'https://api.telegram.org/botprivate-token/sendMessage', ['ok' => true, 'result' => ['message_id' => 456]]],
        'pushover' => [['user_key' => str_repeat('u', 30), 'token' => 'private-token'], 'https://api.pushover.net/1/messages.json', ['status' => 1, 'request' => '789']],
        'ntfy' => [['server' => 'https://ntfy.example', 'topic' => 'private-token', 'token' => 'private-token'], 'https://ntfy.example', ['id' => 'abc']],
        'webhook' => [['url' => 'https://hooks.example/private-token', 'secret' => 'private-token'], 'https://hooks.example/private-token', []],
    ];
}

/** One synchronous send through the channel driver, as the delivery job does. */
function transportSend(ChannelType $channel, AlertMessage $message, DestinationConfig $config, string $idempotencyKey): DeliveryResult
{
    return $channel->send(ChannelRecipient::forChannel($config), new AlertNotification($message, $idempotencyKey));
}

function transportAlert(string $state, ?string $titleKey = null, string $label = 'example.com', string $summary = 'example.com', string $siteUrl = 'https://example.com', string $link = 'https://heptapod.example/incidents/42'): AlertMessage
{
    return new AlertMessage(
        eventId: 42, severity: $state === 'recovered' ? AlertSeverity::Recovered : AlertSeverity::Down,
        titleKey: $titleKey ?? ($state === 'recovered' ? 'notifications.recovered' : 'notifications.down'), titleParams: ['site' => $label],
        summaryKey: 'notifications.failed', summaryParams: ['site' => $summary],
        siteId: 7, siteLabel: $label, siteUrl: $siteUrl, checkType: 'uptime',
        since: CarbonImmutable::parse('2026-10-01T12:30:00Z'), downtimeSeconds: $state === 'recovered' ? 90 : null,
        cause: $state === 'errored' ? 'errored' : 'failed', link: $link,
    );
}

/**
 * A real uptime incident event built for its mail destination, as the delivery job builds it: the opening snapshot carries the
 * reason text, the site its label and URL. Mail renders only these block messages.
 */
function transportMailMessage(NotificationDestination $destination, string $state, string $label, string $reason, string $siteUrl = 'https://example.com'): AlertMessage
{
    test()->travelTo(CarbonImmutable::parse('2026-10-01T12:30:00Z'));
    $errored = $state === 'errored';
    $opened = notificationIncidentEvent(['cause' => $errored ? 'errored' : 'failed', 'to_result' => $errored ? CheckResult::Errored : CheckResult::Failed,
        'payload' => $errored ? ['result' => 'errored-or-timed-out'] : []]);
    $opened->site->update(['friendly_name' => $label, 'url' => $siteUrl]);
    // Alert mail times use the site's client zone (BuildAlertMailContext); N8 owns any change to that.
    $opened->site->client->update(['timezone' => 'Europe/Berlin']);
    $opened->incident->update(['cause' => $errored ? 'errored' : 'failed', 'opened_at' => now(), 'first_failure_at' => now(),
        'evidence' => ['summary' => 'causes.error', 'summary_params' => ['message' => $reason], 'evidence' => [], 'metrics' => null,
            'error' => $errored ? '{"class":"RuntimeException"}' : null, 'run_ended_at' => now()->format('Y-m-d\TH:i:s.uP')]]);
    $event = $opened;
    if ($state === 'recovered') {
        Delivery::query()->create(['event_id' => $opened->id, 'destination_id' => $destination->id, 'state' => DeliveryState::Sent, 'sent_at' => now(),
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
        test()->travel(90)->seconds();
        $event = notificationRecoveryEvent($opened);
    }

    return app(NotificationMessage::class)->build($event->refresh(), $destination);
}

it('bounds provider text without splitting UTF8 and preserves its link', function (string $key, int $titleLimit, int $bodyLimit, string $sample): void {
    [$values, $url, $reply] = notificationTransports()[$key];
    Http::preventStrayRequests();
    Http::fake([$url => Http::response($reply)]);
    $channel = app(ChannelRegistry::class)->get($key);
    $config = new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example');
    $message = transportAlert('failed', label: str_repeat($sample, 24000), summary: str_repeat($sample, 24000));
    expect(transportSend($channel, $message, $config, '42:9')->status)->toBe(DeliveryState::Sent);
    $request = Http::recorded()->sole()[0];
    $body = $request->data();
    if ($key === 'pushover') {
        preg_match_all('/name="([^"]+)"(?:\r\n[^\r\n]+)*\r\n\r\n(.*?)\r\n--/s', $request->body(), $parts);
        $body = array_combine($parts[1], $parts[2]);
    }
    [$title, $text, $link] = match ($key) {
        'slack' => [$body['blocks'][0]['text']['text'], $body['blocks'][1]['text']['text'], $body['blocks'][2]['text']['text']],
        'discord' => [$body['embeds'][0]['title'], $body['embeds'][0]['description'], $body['embeds'][0]['url']],
        'microsoftTeams' => [$body['attachments'][0]['content']['body'][0]['text'], $body['attachments'][0]['content']['body'][1]['text'], $body['attachments'][0]['content']['actions'][0]['url']],
        'telegram' => ['', $body['text'], $message->link],
        'pushover' => [$body['title'], $body['message'], $body['url']],
        'ntfy' => [$body['title'], $body['message'], $body['click']],
    };
    expect(strlen($title))->toBeLessThanOrEqual($titleLimit);
    expect(strlen($text))->toBeLessThanOrEqual($bodyLimit);
    expect(mb_check_encoding($title.$text, 'UTF-8'))->toBeTrue();
    expect($text)->toContain('shortened');
    expect($link)->toBe('https://heptapod.example/incidents/42');
    if ($key === 'telegram') {
        expect($text)->toEndWith('https://heptapod.example/incidents/42');
    }
    if ($key === 'microsoftTeams') {
        expect(strlen($request->body()))->toBeLessThanOrEqual(27000);
    }
    Http::assertSentCount(1);
})->with([
    'slack' => ['slack', 150, 3000], 'discord' => ['discord', 256, 4096], 'microsoftTeams' => ['microsoftTeams', 150, 4000],
    'telegram' => ['telegram', 0, 4096], 'pushover' => ['pushover', 250, 1024], 'ntfy' => ['ntfy', 4096, 4096],
])->with(['emoji' => '🚨', 'stars' => '*', 'quotes' => '"', 'backslashes' => '\\', 'slashes' => '/', 'controls' => "\x01"]);

it('rejects a Teams envelope whose retained link exceeds its serialized budget before sending', function (): void {
    [$values, $url, $reply] = notificationTransports()['microsoftTeams'];
    Http::preventStrayRequests();
    Http::fake([$url => Http::response($reply)]);
    $channel = app(ChannelRegistry::class)->get('microsoftTeams');
    $config = new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example');
    $message = transportAlert('failed', link: 'https://heptapod.example/'.str_repeat('a', 30000));
    expect(fn (): DeliveryResult => transportSend($channel, $message, $config, '42:9'))->toThrow(LengthException::class, 'serialized payload budget');
    Http::assertNothingSent();
});

it('refuses mail for an alert message built without its mail destination', function (): void {
    Mail::fake();
    $channel = app(ChannelRegistry::class)->get('mail');
    $config = new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig(['email' => 'operator@example.com']), 'UTC', 'Heptapod', 'https://heptapod.example');
    expect(fn (): DeliveryResult => transportSend($channel, transportAlert('failed'), $config, '42:9'))->toThrow(LogicException::class, 'build the message for its mail destination');
    Mail::assertNothingSent();
});

it('bounds mail and webhook rendering including an oversized site URL', function (string $key): void {
    [$values, $url, $reply] = notificationTransports()[$key];
    Http::preventStrayRequests();
    Http::fake([$url => Http::response($reply)]);
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = app(ChannelRegistry::class)->get($key);
    $siteUrl = 'https://example.com/'.str_repeat('a', 50000);
    if ($key === 'mail') {
        $destination = NotificationDestination::factory()->create(['locale' => 'en', 'config' => $channel->configSchema()->validateConfig($values)]);
        // Site names are at most 255 characters (sites.friendly_name); the reason and URL carry the oversized input.
        $message = transportMailMessage($destination, 'failed', str_repeat('Ä', 255), str_repeat('Ä', 12000), $siteUrl);
        expect(transportSend($channel, $message, $destination->destinationConfig(), '42:'.$destination->id)->status)->toBe(DeliveryState::Sent);
        $mail = $transport->messages()->sole()->getOriginalMessage();
        // Block mail keeps the channels' render limits (RenderedAlert::RENDER_LIMITS): subject and each text value at most
        // 1000 bytes, the plain body within the 16000-byte body budget.
        expect(strlen($mail->getSubject()))->toBeLessThanOrEqual(1000)
            ->and(strlen($mail->getTextBody()))->toBeLessThanOrEqual(16000)
            ->and($mail->getSubject())->toEndWith('…');
        expect(mb_check_encoding($mail->getSubject().$mail->getTextBody().$mail->getHtmlBody(), 'UTF-8'))->toBeTrue()
            ->and($mail->getHtmlBody().$mail->getTextBody())->not->toContain(str_repeat('a', 2049))
            ->and($mail->getTextBody())->toContain($message->link)
            ->and($mail->getTo()[0]->getAddress())->toBe('operator@example.com');

        return;
    }
    $config = new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example');
    $message = transportAlert('failed', label: str_repeat('Ä', 12000), summary: str_repeat('Ä', 12000), siteUrl: $siteUrl);
    expect(transportSend($channel, $message, $config, '42:9')->status)->toBe(DeliveryState::Sent);
    $request = Http::recorded()->sole()[0];
    expect(strlen($request['title']))->toBeLessThanOrEqual(1000);
    expect(strlen($request['summary']))->toBeLessThanOrEqual(16000);
    expect(strlen($request['site_url']))->toBeLessThanOrEqual(2048);
    expect(strlen($request['site_label']))->toBeLessThanOrEqual(1000);
    expect($request['link'])->toBe($message->link);
    Http::assertSentCount(1);
})->with(['mail', 'webhook']);

it('delivers localized payloads through each real transport', function (string $key, string $locale, string $state, bool $hostile, ?string $timezone): void {
    [$values, $url, $reply] = notificationTransports()[$key];
    Http::preventStrayRequests();
    Http::fake([$url => Http::response($reply)]);
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = app(ChannelRegistry::class)->get($key);
    $instance = app(InstanceSettings::class);
    $instance->name = 'Operations & Monitoring';
    $instance->timezone = 'Europe/Berlin';
    $instance->save();
    // A recipient's own timezone comes from its user since 2026-10-30; team channels use the instance timezone.
    $destination = NotificationDestination::factory()->create(['channel' => $key, 'locale' => $locale,
        'config' => $channel->configSchema()->validateConfig($values), ...($timezone === null ? [] : ['recipient_kind' => RecipientKind::User,
            'user_id' => User::factory()->create(['timezone' => $timezone, 'locale' => $locale])->id])]);
    $config = $destination->destinationConfig();
    if ($key !== 'mail') {
        expect(json_encode($config, JSON_THROW_ON_ERROR))->not->toContain('private-token');
        $handler = new TestHandler;
        new Logger('notification', [$handler])->info('destination', ['config' => $config]);
        expect(json_encode($handler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain('private-token');
    }
    $label = $hostile ? '[L](https://evil.test) @everyone' : 'example.com';
    $summary = $hostile ? '![I](https://evil.test/i) <b>X</b> <https://evil.test|T>' : 'example.com';
    if ($key === 'mail') {
        $message = transportMailMessage($destination, $state, $label, $summary);
        expect(transportSend($channel, $message, $config, $message->eventId.':'.$destination->id)->status)->toBe(DeliveryState::Sent);
        $mail = $transport->messages()->sole()->getOriginalMessage();
        $html = $mail->getHtmlBody();
        $text = $mail->getTextBody();
        expect($mail->getTo()[0]->getAddress())->toBe('operator@example.com')
            ->and($html)->toContain('lang="'.$locale.'"', 'Operations &amp; Monitoring', htmlspecialchars($label, ENT_QUOTES))
            ->and($text)->toContain('Operations & Monitoring', $label);
        // Localized times, never the raw instant.
        expect($text)->toContain(match ($state) {
            'failed' => $locale === 'en' ? 'since 14:30 CEST' : 'seit 14:30 CEST',
            'recovered' => '14:30 CEST – 14:31 CEST',
            'errored' => $locale === 'en' ? 'Oct 1, 2026, 14:30 CEST' : '1. Okt 2026, 14:30 CEST',
        })->not->toContain('2026-10-01T12:30');
        expect($mail->getSubject())->toBe(match ([$locale, $state]) {
            ['en', 'failed'] => '[Down] '.$label.' — '.$summary.' since 14:30 CEST',
            ['de', 'failed'] => '[Ausgefallen] '.$label.' — '.$summary.' seit 14:30 CEST',
            ['en', 'recovered'] => '[Recovered] '.$label.' after 2 min',
            ['de', 'recovered'] => '[Entwarnung] '.$label.' nach 2 Min.',
            ['en', 'errored'] => '[Check error] '.$label.' — the Uptime check could not run',
            ['de', 'errored'] => '[Prüffehler] '.$label.' — Prüfung „Erreichbarkeit“ konnte nicht ausgeführt werden',
        });
        if ($hostile) {
            // Hostile labels and reasons stay text: no injected links, images or markup.
            expect($html)->not->toContain('href="https://evil.test', 'src="https://evil.test', '<b>X</b>');
        }
        if ($state === 'errored') {
            expect($html.$text)->not->toContain(' is down', 'ist ausgefallen');
        }
        if ($state === 'recovered') {
            expect($text)->toContain($locale === 'en' ? 'Recovered' : 'Entwarnung');
        }
        Http::assertNothingSent();

        return;
    }
    $result = transportSend($channel, transportAlert($state, label: $label, summary: $summary), $config, '42:'.$destination->id);
    expect($result->status)->toBe(DeliveryState::Sent);
    expect($result->providerMessageId)->toBe(match ($key) {
        'slack' => '123.456', 'discord' => '321', 'telegram' => '456', 'pushover' => '789', 'ntfy' => 'abc',
        default => null,
    });
    $title = match ([$locale, $state]) {
        ['en', 'failed'] => 'example.com is down',
        ['de', 'failed'] => 'example.com ist ausgefallen',
        ['en', 'recovered'] => 'example.com has recovered',
        ['de', 'recovered'] => 'example.com ist wieder erreichbar',
        ['en', 'errored'] => 'example.com — Uptime could not run (error)',
        ['de', 'errored'] => 'example.com — Erreichbarkeit konnte nicht ausgeführt werden (Fehler)',
    };
    if ($key === 'webhook' && $state === 'errored') {
        $title = str_replace($locale === 'en' ? 'Uptime' : 'Erreichbarkeit', 'uptime', $title);
    }
    $since = $key === 'webhook' ? '2026-10-01T12:30:00+00:00' : match ([$locale, $timezone]) {
        ['en', null] => 'Oct 1, 2026 2:30 PM CEST',
        ['de', null] => '1. Okt 2026 14:30 CEST',
        ['en', 'America/New_York'] => 'Oct 1, 2026 8:30 AM EDT',
        ['de', 'America/New_York'] => '1. Okt 2026 08:30 EDT',
    };
    $checkLabel = $key === 'webhook' ? 'uptime' : ($locale === 'en' ? 'Uptime' : 'Erreichbarkeit');
    $expectedLabel = $hostile && in_array($key, ['discord', 'microsoftTeams'], true) ? '\\[L\\]\\(https://evil.test\\) @everyone' : $label;
    $title = str_replace('example.com', $expectedLabel, $title);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($key, $title, $state, $url, $hostile, $summary, $since, $checkLabel, $destination): bool {
        expect($request->url())->toBe($url);
        expect($request->method())->toBe('POST');
        if ($key === 'telegram') {
            parse_str($request->body(), $body);
            expect($body['chat_id'])->toBe('123');
            expect($body['text'])->toContain($title, $since, $checkLabel, 'https://example.com', 'https://heptapod.example/incidents/42');
            expect($body)->not->toHaveKey('parse_mode');
            $text = $body['text'];
        } elseif ($key === 'pushover') {
            $text = $request->body();
            expect($text)->toContain('name="title"', $title, 'name="message"', $since, $checkLabel, 'name="user"', str_repeat('u', 30), 'https://example.com', 'https://heptapod.example/incidents/42');
        } else {
            $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $text = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            expect($text)->toContain(substr(json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 1, -1), $since, $checkLabel, 'https://example.com', 'https://heptapod.example/incidents/42');
            match ($key) {
                'slack' => expect($body['blocks'][0]['type'])->toBe('header')->and($body['blocks'][1]['type'])->toBe('section')->and($body['channel'])->toBe('C123')->and($request->header('Authorization'))->toBe(['Bearer private-token']),
                'discord' => expect($body['embeds'][0]['title'])->toBe($title)->and($body['enforce_nonce'])->toBeTrue()->and($body['nonce'])->toHaveLength(24)->and($request->header('Authorization'))->toBe(['Bot private-token']),
                'microsoftTeams' => expect($body['attachments'][0]['content']['type'])->toBe('AdaptiveCard')->and($body['attachments'][0]['content']['body'][0]['text'])->toBe($title),
                'ntfy' => expect($body['topic'])->toBe('private-token')->and($body['title'])->toBe($title)->and($request->header('Authorization'))->toBe(['Bearer private-token']),
                'webhook' => expect($body['event_id'])->toBe(42)->and($body['destination_id'])->toBe($destination->id)->and($body['is_test'])->toBeFalse()->and($body['severity'])->toBe($state === 'errored' ? 'error' : ($state === 'recovered' ? 'recovered' : 'down'))
                    ->and($request->header('X-Heptapod-Delivery'))->toBe(['42:'.$destination->id])->and($request->header('Idempotency-Key'))->toBe(['42:'.$destination->id])
                    ->and($request->header('Signature'))->toBe([hash_hmac('sha256', $request->header('X-Heptapod-Timestamp')[0].'.'.$request->body(), 'private-token')]),
            };
        }
        if ($hostile) {
            if ($key === 'slack') {
                expect($body['blocks'][0]['text']['type'])->toBe('plain_text');
                expect($body['blocks'][1]['text']['type'])->toBe('plain_text');
                expect($body['mrkdwn'])->toBeFalse();
                expect($body['text'])->not->toContain('<https://evil.test|T>');
            }
            if ($state !== 'errored' && in_array($key, ['discord', 'microsoftTeams'], true)) {
                $description = $key === 'discord' ? $body['embeds'][0]['description'] : $body['attachments'][0]['content']['body'][1]['text'];
                expect($description)->toContain('\\!\\[I\\]\\(https://evil.test/i\\)', '\\<b\\>X\\</b\\>', '\\<https://evil.test\\|T\\>');
            }
            if ($key === 'discord') {
                expect($body['allowed_mentions']['parse'])->toBe([]);
            }
            if ($key === 'telegram' && $state !== 'errored') {
                expect($body['text'])->toContain($summary);
            }
        }
        if ($state === 'errored') {
            expect($text)->not->toContain(' is down', 'ist ausgefallen');
        }
        if ($state === 'recovered') {
            expect($text)->toContain('90');
        }

        return true;
    });
})->with(array_map(fn (string $key): array => [$key], array_keys(notificationTransports())))->with(['en', 'de'])->with(['failed', 'errored', 'recovered'])->with(['ordinary' => false, 'hostile markup' => true])->with(['instance timezone' => null, 'destination timezone' => 'America/New_York']);

it('returns secret-free failure results for HTTP and network rejections', function (string $key, bool $network): void {
    [$values, $url] = notificationTransports()[$key];
    Http::preventStrayRequests();
    Http::fake([$url => $network ? Http::failedConnection('private-token') : Http::response(['error' => 'private-token'], 500)]);
    $channel = app(ChannelRegistry::class)->get($key);
    $result = transportSend($channel, transportAlert('failed'), new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example'), '42:9');
    expect($result->status)->toBe(DeliveryState::Failed);
    expect($result->reason)->toBe('notifications.transport_failed');
    expect($result->toJson())->not->toContain('private-token');
})->with(array_map(fn (string $key): array => [$key], array_values(array_diff(array_keys(notificationTransports()), ['mail']))))->with([false, true]);

it('rejects HTTP-200 provider failures rather than claiming delivery', function (string $key, array $reply): void {
    [$values, $url] = notificationTransports()[$key];
    Http::fake([$url => Http::response($reply)]);
    $channel = app(ChannelRegistry::class)->get($key);
    $result = transportSend($channel, transportAlert('failed'), new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example'), '42:9');
    expect($result->status)->toBe(DeliveryState::Failed)->and($result->toJson())->not->toContain('private-token');
    expect($result->diagnosis)->toContain('HTTP 200')->not->toContain('[redacted]');
})->with([
    'Slack' => ['slack', ['ok' => false, 'error' => 'private-token']],
    'Telegram' => ['telegram', ['ok' => false, 'description' => 'private-token']],
    'Pushover' => ['pushover', ['status' => 0, 'errors' => ['private-token']]],
    'Discord' => ['discord', ['code' => 50001, 'message' => 'private-token']],
]);

it('interprets Microsoft Teams workflow acknowledgements', function (string $body, int $status, string $expectedStatus, ?string $expectedReason): void {
    [$values, $url] = notificationTransports()['microsoftTeams'];
    Http::preventStrayRequests();
    Http::fake([$url => Http::response($body, $status, ['Content-Type' => 'text/plain'])]);
    $channel = app(ChannelRegistry::class)->get('microsoftTeams');
    $config = new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example');

    $result = transportSend($channel, transportAlert('failed'), $config, '42:9');

    expect($result->status->value)->toBe($expectedStatus);
    expect($result->reason)->toBe($expectedReason);
    expect($result->toJson())->not->toContain('private-token');
    if ($body === 'Microsoft Teams endpoint returned HTTP error 429') {
        expect($result->diagnosis)->toBe('HTTP 200');
    }
    Http::assertSentCount(1);
})->with([
    'workflow accepted' => ['', 202, 'sent', null],
    'workflow success' => ['', 200, 'sent', null],
    'retired connector acknowledgement' => ['1', 200, 'failed', 'notifications.transport_failed'],
    'throttled body' => ['Microsoft Teams endpoint returned HTTP error 429', 200, 'failed', 'notifications.transport_failed'],
    'other provider error' => ['private-token', 200, 'failed', 'notifications.transport_failed'],
    'non-empty whitespace' => [' ', 200, 'failed', 'notifications.transport_failed'],
]);

it('does not misclassify rendering errors as Slack provider failures', function (): void {
    [$values] = notificationTransports()['slack'];
    Http::preventStrayRequests();
    Http::fake();
    $channel = app(ChannelRegistry::class)->get('slack');
    $config = new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example');
    expect(fn (): DeliveryResult => transportSend($channel, transportAlert('failed', 'notifications.missing'), $config, '42:9'))
        ->toThrow(UnexpectedValueException::class, 'Missing alert translation: notifications.missing');
    Http::assertNothingSent();
});

it('uses stable Discord nonces for replay and distinct nonces for other deliveries', function (): void {
    [$values, $url, $reply] = notificationTransports()['discord'];
    Http::fake([$url => Http::response($reply)]);
    $channel = app(ChannelRegistry::class)->get('discord');
    $config = new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example');
    foreach (['42:9', '42:9', '43:9'] as $key) {
        transportSend($channel, transportAlert('failed'), $config, $key);
    }
    $nonces = Http::recorded()->map(fn (array $pair): string => json_decode($pair[0]->body(), true, flags: JSON_THROW_ON_ERROR)['nonce'])->all();
    expect($channel->supportsIdempotencyKey())->toBeTrue();
    expect($nonces[0])->toBe($nonces[1])->not->toBe($nonces[2]);
});

it('has matching EN and DE addon translation keys', function (): void {
    $en = json_decode(file_get_contents(base_path('addons/channels/lang/en.json')), true, flags: JSON_THROW_ON_ERROR);
    $de = json_decode(file_get_contents(base_path('addons/channels/lang/de.json')), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($en))->toBe(array_keys($de));
});

it('requires a 2xx acknowledgement without following redirects for every HTTP transport', function (string $key, bool $location): void {
    [$values, $url, $reply] = notificationTransports()[$key];
    Http::preventStrayRequests();
    Http::fake([
        $url => Http::response($reply, 302, $location ? ['Location' => 'https://login.example'] : []),
        'https://login.example' => Http::response($reply),
    ]);
    $channel = app(ChannelRegistry::class)->get($key);
    $result = transportSend($channel, transportAlert('failed'), new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example'), '42:9');
    expect($result->status)->toBe(DeliveryState::Failed)->and($result->reason)->toBe('notifications.transport_failed');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $url && $request->method() === 'POST');
})->with(array_map(fn (string $key): array => [$key], array_values(array_diff(array_keys(notificationTransports()), ['mail']))))->with(['redirect' => true, 'terminal 3xx' => false]);

it('diagnoses HTTP rejection with provider details without package-owned retries', function (string $key, int $status): void {
    [$values, $url] = notificationTransports()[$key];
    Http::preventStrayRequests();
    Http::fake([$url => Http::response(['error_code' => 40401, 'description' => 'Destination not found'], $status)]);
    $channel = app(ChannelRegistry::class)->get($key);
    $result = transportSend($channel, transportAlert('failed'), new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example'), '42:9');
    expect($result->status)->toBe(DeliveryState::Failed);
    expect($result->diagnosis)->toBe('HTTP '.$status.' — RequestException'.($key === 'telegram' ? ' — Destination not found' : ''));
    Http::assertSentCount(1);
})->with(array_map(fn (string $key): array => [$key], array_values(array_diff(array_keys(notificationTransports()), ['mail']))))->with([404, 429]);

it('localizes component summary timestamps for people but preserves webhook ISO timestamps', function (string $key, string $locale, string $at, string $timestamp = '2026-10-02T04:39:47+00:00'): void {
    [$values, $url, $reply] = notificationTransports()[$key];
    Http::preventStrayRequests();
    Http::fake([$url => Http::response($reply)]);
    $channel = app(ChannelRegistry::class)->get($key);
    $message = new AlertMessage(42, AlertSeverity::Recovered, 'notifications.component_recovered_title', ['site' => 'example.com', 'component' => 'Backup'],
        'notifications.component_recovered_summary', ['component' => 'Backup', 'at' => $timestamp],
        7, 'example.com', 'https://example.com', 'cron', CarbonImmutable::parse($timestamp)->subMinutes(5), 300, null, 'https://heptapod.example/incidents/42');
    $config = new DestinationConfig(9, $locale, $channel->configSchema()->validateConfig($values), 'Europe/Berlin', 'Heptapod', 'https://heptapod.example');

    expect(transportSend($channel, $message, $config, '42:9')->status)->toBe(DeliveryState::Sent);
    $request = Http::recorded()->sole()[0];
    $text = $key === 'webhook' ? $request['summary'] : $request['text'];
    expect($text)->toContain('Backup', $at, '300');
    Http::assertSentCount(1);
})->with([
    'telegram EN' => ['telegram', 'en', 'Oct 2, 2026 6:39 AM CEST'],
    'telegram DE' => ['telegram', 'de', '2. Okt 2026 06:39 CEST'],
    'webhook EN' => ['webhook', 'en', '2026-10-02T04:39:47+00:00'],
    'webhook DE' => ['webhook', 'de', '2026-10-02T04:39:47+00:00'],
    'spring before EN' => ['telegram', 'en', 'Mar 29, 2026 1:59 AM CET', '2026-03-29T00:59:59+00:00'],
    'spring after EN' => ['telegram', 'en', 'Mar 29, 2026 3:00 AM CEST', '2026-03-29T01:00:00+00:00'],
    'spring before DE' => ['telegram', 'de', '29. Mär 2026 01:59 CET', '2026-03-29T00:59:59+00:00'],
    'spring after DE' => ['telegram', 'de', '29. Mär 2026 03:00 CEST', '2026-03-29T01:00:00+00:00'],
    'autumn first EN' => ['telegram', 'en', 'Oct 25, 2026 2:30 AM CEST', '2026-10-25T00:30:00+00:00'],
    'autumn second EN' => ['telegram', 'en', 'Oct 25, 2026 2:30 AM CET', '2026-10-25T01:30:00+00:00'],
    'autumn first DE' => ['telegram', 'de', '25. Okt 2026 02:30 CEST', '2026-10-25T00:30:00+00:00'],
    'autumn second DE' => ['telegram', 'de', '25. Okt 2026 02:30 CET', '2026-10-25T01:30:00+00:00'],
]);

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Channels\TimestampSigner;
use Heptapod\Contracts\Notifications\AlertMessage;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('receives live ntfy and signed webhook notifications without provider credentials', function (string $key): void {
    $server = $key === 'ntfy' ? 'https://ntfy.sh' : 'http://fixtures.test';
    try {
        $probe = Http::timeout(10)->connectTimeout(5)->get($server.($key === 'ntfy' ? '/v1/health' : '/status/200'));
    } catch (ConnectionException) {
        $this->markTestSkipped('Network or fixture service is unavailable.');
    }
    expect($probe->successful())->toBeTrue();
    $identity = 'heptapod-smoke-'.Str::uuid();
    $url = $server.'/webhook/'.$identity;
    $secret = Str::random(32);
    $values = $key === 'ntfy' ? ['server' => $server, 'topic' => $identity] : ['url' => $url, 'secret' => $secret, 'allow_insecure_http' => true];
    $channel = app(ChannelRegistry::class)->get($key);
    $message = new AlertMessage(
        0, AlertSeverity::Info, 'notifications.test_title', [], 'notifications.test_summary', [],
        0, 'Heptapod', 'https://heptapod.example', null, CarbonImmutable::now('UTC'), null, null, 'https://heptapod.example', true,
    );
    $result = $channel->send(ChannelRecipient::forChannel(new DestinationConfig(9, 'en', $channel->configSchema()->validateConfig($values), 'UTC', 'Heptapod', 'https://heptapod.example')), new AlertNotification($message, $identity));
    expect($result->status)->toBe(DeliveryState::Sent, $result->diagnosis ?? '');
    if ($key === 'ntfy') {
        // ntfy acknowledges before its cache write becomes visible to polling subscribers.
        $body = '';
        for ($attempt = 0; $attempt < 10 && $body === ''; $attempt++) {
            if ($attempt > 0) {
                sleep(1);
            }
            $body = trim(Http::timeout(20)->get($server.'/'.$identity.'/json', ['poll' => '1', 'since' => 'all'])->throw()->body());
        }
        expect($body)->not->toBeEmpty();
        $messages = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", $body));
        expect($messages)->toHaveCount(1);
        expect($messages[0]['id'])->toBe($result->providerMessageId);
        expect($messages[0]['topic'])->toBe($identity);
        expect($messages[0]['title'])->toBe('[Test] Heptapod test notification');
        fwrite(STDOUT, "\nntfy: sent, JSON poll received message ".$result->providerMessageId.' on random topic '.$identity."\n");
    } else {
        $receipt = Http::timeout(10)->get($url)->throw()->json();
        $payload = json_decode($receipt['body'], true, flags: JSON_THROW_ON_ERROR);
        expect($payload['is_test'])->toBeTrue();
        expect($payload['title'])->toBe('[Test] Heptapod test notification');
        expect($receipt['headers']['x-heptapod-delivery'])->toBe('0:9');
        expect($receipt['headers']['idempotency-key'])->toBe($identity);
        $signer = new TimestampSigner((int) $receipt['headers']['x-heptapod-timestamp']);
        expect($receipt['headers'][strtolower($signer->signatureHeaderName())])->toBe($signer->calculateSignature($url, $payload, $secret));
        expect($receipt['body'])->toBe(json_encode($payload, JSON_THROW_ON_ERROR));
        fwrite(STDOUT, "\nwebhook: sent, stored body/headers received, timestamped Spatie Signer HMAC verified on ".$url."\n");
    }
})->with(['ntfy', 'webhook'])->group('notification-live')->skip(fn (): bool => getenv('HEPTAPOD_NOTIFICATION_LIVE') !== '1', 'Manual only: enable HEPTAPOD_NOTIFICATION_LIVE=1 with network and Compose fixtures.');

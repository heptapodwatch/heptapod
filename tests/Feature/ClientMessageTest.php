<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\SendClientMessage;
use Heptapod\Actions\SendNotificationDelivery;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\FakeSmtpTransport;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    // A Wednesday 23:30 UTC: inside the client's quiet hours, which a manual send ignores.
    $this->travelTo(CarbonImmutable::parse('2026-10-28T23:30:00Z'));
    config(['mail.default' => 'smtp']);
    $settings = app(InstanceSettings::class);
    $settings->name = 'Studio Nord';
    $settings->timezone = 'UTC';
    $settings->save();
    Storage::fake('local');
    Queue::fake();
});

function clientMessageUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

function clientMessageChannel(Client $client, string $address, array $attributes = []): NotificationDestination
{
    $channel = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $client->id, 'user_id' => null,
        'channel' => 'mail', 'label' => 'Office', 'locale' => null, 'enabled' => true, ...$attributes,
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => $address])]);
    NotificationPreference::writeInitialGrid($channel->id, NotificationPreference::defaults(RecipientKind::Client));

    return $channel;
}

/** A broken-links check with $count current findings on the client's site. */
function clientMessageFindings(Client $client, int $count = 3, string $host = 'shop.example.test'): Check
{
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://'.$host]);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::BrokenLinks, 'latest_run_result' => CheckResult::Failed]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'broken_links.failed', 'started_at' => CarbonImmutable::parse('2026-10-28T21:38:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T21:40:00Z')]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $snapshot = DB::table('crawl_snapshots')->insertGetId(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000001', 'run_ended_at' => $run->ended_at, 'collected_at' => $run->ended_at,
        'settings' => '{}', 'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => true, 'evaluated_finding_ids' => '[]', 'site_url' => $site->url, 'target_fingerprint' => str_repeat('a', 64),
        'classification_policy_version' => null]);
    for ($i = 1; $i <= $count; $i++) {
        DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => "https://shop.example.test/missing-{$i}", 'relative_crawled_url' => "/missing-{$i}",
            'status_code' => 404, 'found_on_url' => 'https://shop.example.test/catalog', 'link_text' => "=Part {$i}", 'type' => 'link', 'internal' => true]);
    }

    return $check;
}

/** @return array<string, string> file name → contents, without the inline logo */
function clientMessageFiles(Email $mail): array
{
    $files = [];
    foreach ($mail->getAttachments() as $part) {
        if ($part->getDisposition() !== 'inline') {
            $files[$part->getFilename()] = $part->getBody();
        }
    }

    return $files;
}

/** @return list<Email> */
function clientMessageDeliver(Event|int $event): array
{
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $eventId = $event instanceof Event ? $event->id : $event;
    foreach (Delivery::query()->where('event_id', $eventId)->orderBy('id')->pluck('id') as $id) {
        app(SendNotificationDelivery::class)($id);
    }

    return array_map(static fn ($sent) => $sent->getOriginalMessage(), $transport->messages()->all());
}

it('rejects an invalid one-off address with concise localized feedback before preview or send', function (string $locale, string $message): void {
    $sender = clientMessageUser('Admin', ['locale' => $locale]);
    $client = Client::factory()->create();
    $check = Check::factory()->for(Site::factory()->for($client))->create(['type' => CheckKey::BrokenLinks]);
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [], 'one_off' => 'invalid-address'];

    $this->actingAs($sender);
    foreach (['clients.messages.preview', 'clients.messages.store'] as $route) {
        $this->postJson(route($route, $client), $payload, ['Idempotency-Key' => 'invalid-one-off'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.one_off.0', $message);
    }
    expect(Event::query()->where('type', 'like', 'client_message.%')->count())->toBe(0);
})->with([
    'EN' => ['en', 'This address is not a valid email address.'],
    'DE' => ['de', 'Diese Adresse ist keine gültige E-Mail-Adresse.'],
]);

it('previews without writing, then sends once per key with frozen addresses, Reply-To, CSV and audit', function (): void {
    $sender = clientMessageUser('Admin', ['name' => 'Anna Berger', 'email' => 'anna@studio.test']);
    $client = Client::factory()->create(['name' => 'Müller GmbH', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
    $office = clientMessageChannel($client, 'office@mueller.test');
    $paused = clientMessageChannel($client, 'thomas@mueller.test', ['label' => 'Thomas Müller', 'enabled' => false]);
    $check = clientMessageFindings($client, 23);
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id, $paused->id], 'note' => 'Bitte bis Freitag prüfen.', 'attach' => true];
    $before = [Event::query()->count(), Delivery::query()->count(), Activity::query()->count()];

    $preview = $this->actingAs($sender)->postJson(route('clients.messages.preview', $client), $payload)->assertOk()->json();
    expect($preview['subject'])->toBe('Defekte Links auf shop.example.test: 23 gefunden')
        ->and($preview['html'])->toContain('am 28.10.2026 um 22:40 Uhr haben wir auf shop.example.test 23 Links gefunden', 'Bitte bis Freitag prüfen.', 'und 3 weitere; alle finden Sie in der angehängten CSV-Datei.')
        ->and($preview['html'])->not->toContain('href="http')
        ->and($preview['recipients'])->toBe([['id' => $office->id, 'address' => 'office@mueller.test', 'paused' => false], ['id' => $paused->id, 'address' => 'thomas@mueller.test', 'paused' => true]])
        ->and([Event::query()->count(), Delivery::query()->count(), Activity::query()->count()])->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $first = $this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'send-1'])->assertCreated()->json();
    $again = $this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'send-1'])->assertOk()->json();
    expect($again)->toBe([...$first, 'replayed' => true]);
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'note' => 'Anders.'], ['Idempotency-Key' => 'send-1'])->assertConflict();
    // The same key never reaches a recipient added later.
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'one_off' => 'late@mueller.test'], ['Idempotency-Key' => 'send-1'])->assertConflict();
    expect(Event::query()->where('type', 'client_message.findings')->count())->toBe(1);

    $event = Event::query()->findOrFail($first['event_id']);
    $deliveries = Delivery::query()->where('event_id', $event->id)->orderBy('id')->get();
    expect($deliveries->map(fn (Delivery $delivery): array => [$delivery->origin, $delivery->actor_id, $delivery->actor_name, $delivery->recipient_snapshot])->all())->toBe([
        ['manual', $sender->id, 'Anna Berger', ['name' => 'Office', 'locale' => 'de', 'address' => 'office@mueller.test']],
        ['manual', $sender->id, 'Anna Berger', ['name' => 'Thomas Müller', 'locale' => 'de', 'address' => 'thomas@mueller.test']],
    ])->and($event->payload['sender'])->toBe(['name' => 'Anna Berger', 'address' => 'anna@studio.test']);
    $attachment = DB::table('client_message_attachments')->where('event_id', $event->id)->sole();
    $csv = Storage::disk('local')->get($attachment->path);
    expect($attachment->rows)->toBe(23)->and($attachment->bytes)->toBe(strlen($csv))->and($attachment->sha256)->toBe(hash('sha256', $csv))
        ->and($csv)->toStartWith("\xEF\xBB\xBFAdresse,Ergebnis,Einordnung,\"Gefunden auf\",Linktext\r\n")->toContain("'=Part 1");
    $audit = Activity::query()->where('event', 'notification.client_message')->sole();
    expect($audit->causer_id)->toBe($sender->id)->and($audit->properties['channel_ids'])->toBe([$office->id, $paused->id]);

    // Edited after approval: the snapshot address is used. Sender's address changed: Reply-To stays as approved.
    $office->update(['config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'redirect@elsewhere.test'])]);
    $sender->update(['email' => 'anna.new@studio.test']);
    $mails = clientMessageDeliver($event);
    expect(array_map(fn (Email $mail): string => $mail->getTo()[0]->getAddress(), $mails))->toBe(['office@mueller.test', 'thomas@mueller.test'])
        ->and($mails[0]->getReplyTo()[0]->getAddress())->toBe('anna@studio.test')
        ->and($mails[0]->getSubject())->toBe('Defekte Links auf shop.example.test: 23 gefunden')
        ->and($mails[0]->getHtmlBody())->toContain('Guten Tag,', 'Mit freundlichen Grüßen, Anna Berger (Studio Nord)')
        ->and($mails[0]->getHtmlBody())->not->toContain('href="http')
        ->and(clientMessageFiles($mails[0]))->toBe(['defekte-links-shop-example-test-2026-10-28.csv' => $csv])
        ->and(Delivery::query()->where('event_id', $event->id)->pluck('state')->all())->toBe([DeliveryState::Sent, DeliveryState::Sent]);
    // Activity and the check timeline do not list the message; the client page does.
    expect(Event::feedVisible()->where('id', $event->id)->exists())->toBeFalse();
});

it('turns two simultaneous submits with one key into one message', function (): void {
    $sender = clientMessageUser('Owner');
    $client = Client::factory()->create();
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 1);
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]];
    // The race window: this submit passed the unlocked replay check, and the other one's message lands right as this one
    // takes the per-key lock. Holding the lock, it must find that message and replay it instead of sending again.
    $raced = false;
    DB::listen(function ($query) use (&$raced, $sender, $client, $payload): void {
        if (! $raced && str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $raced = true;
            app(SendClientMessage::class)->send($sender, $client, $payload, 'race');
        }
    });
    $first = $this->actingAs($sender)->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'race'])->assertOk()->json();
    expect($raced)->toBeTrue()->and($first['replayed'])->toBeTrue()
        ->and(Event::query()->where('type', 'client_message.findings')->count())->toBe(1)
        ->and(Delivery::query()->where('origin', 'manual')->pluck('id')->all())->toBe($first['delivery_ids']);
});

it('refuses Members, other clients and archived channels, and sends to paused ones', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $office = clientMessageChannel($client, 'office@client.test');
    $archived = clientMessageChannel($client, 'old@client.test', ['removed_at' => CarbonImmutable::now(), 'removed_reason' => 'archived']);
    $foreign = clientMessageChannel($other, 'office@other.test');
    $check = clientMessageFindings($client, 1);
    $otherCheck = clientMessageFindings($other, 1, 'other.example.test');
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]];

    $this->actingAs(clientMessageUser('Member'))->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'm'])->assertForbidden();
    $this->actingAs($admin = clientMessageUser('Admin'))
        ->postJson(route('clients.messages.store', $client), [...$payload, 'channel_ids' => [$foreign->id]], ['Idempotency-Key' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('channel_ids');
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'check_id' => $otherCheck->id], ['Idempotency-Key' => 'b'])->assertNotFound();
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'channel_ids' => [$archived->id]], ['Idempotency-Key' => 'c'])->assertUnprocessable()->assertJsonValidationErrors('channel_ids');
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'channel_ids' => []], ['Idempotency-Key' => 'd'])->assertUnprocessable()->assertJsonValidationErrors('channel_ids');
    expect(Event::query()->where('type', 'like', 'client_message.%')->count())->toBe(0);

    // Paused after approval: still sent. Archived after approval: suppressed.
    $kept = clientMessageChannel($client, 'kept@client.test');
    $sent = $this->postJson(route('clients.messages.store', $client), [...$payload, 'channel_ids' => [$office->id, $kept->id]], ['Idempotency-Key' => 'e'])->assertCreated()->json();
    $office->update(['enabled' => false]);
    $kept->update(['removed_at' => CarbonImmutable::now(), 'removed_reason' => 'archived']);
    $mails = clientMessageDeliver($sent['event_id']);
    expect(array_map(fn (Email $mail): string => $mail->getTo()[0]->getAddress(), $mails))->toBe(['office@client.test'])
        ->and(Delivery::query()->where('event_id', $sent['event_id'])->orderBy('id')->get(['state', 'suppression_reason'])->toArray())
        ->toBe([['state' => 'sent', 'suppression_reason' => null], ['state' => 'pending', 'suppression_reason' => 'destination_archived']]);
});

it('gives a one-off address exactly one attempt and archives it, also after a failure', function (): void {
    $client = Client::factory()->create(['locale' => 'en']);
    $check = clientMessageFindings($client, 1);
    $this->actingAs(clientMessageUser('Owner'));
    $sent = $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [],
        'one_off' => 'boss@client.test'], ['Idempotency-Key' => 'one'])->assertCreated()->json();
    $oneOff = NotificationDestination::query()->where('client_id', $client->id)->sole();
    expect($oneOff->removed_at)->toBeNull();

    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('RCPT TO:<boss@client.test>', 451));
    app(SendNotificationDelivery::class)($sent['delivery_ids'][0]);
    $delivery = Delivery::query()->findOrFail($sent['delivery_ids'][0]);
    // The failed attempt is terminal in the same commit that records it: suppressed, and the channel archived.
    expect([$delivery->state, $delivery->suppressed, $delivery->suppression_reason])->toBe([DeliveryState::Failed, true, 'one_off_attempted'])
        ->and($oneOff->refresh()->removed_reason)->toBe('archived');
    $this->travel(2)->hours();
    expect(app(SendNotificationDelivery::class)($delivery->id))->toBeNull();
});

it('never gives a one-off address a second attempt after a crash or an exception, but keeps retries for saved channels', function (string $failure): void {
    $client = Client::factory()->create(['locale' => 'en']);
    $saved = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 1);
    $this->actingAs(clientMessageUser('Owner'));
    $sent = $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$saved->id],
        'one_off' => 'boss@client.test'], ['Idempotency-Key' => 'crash'])->assertCreated()->json();
    $oneOff = NotificationDestination::query()->where('client_id', $client->id)->whereKeyNot($saved->id)->sole();
    $oneOffDelivery = Delivery::query()->where('destination_id', $oneOff->id)->sole();
    $savedDelivery = Delivery::query()->where('destination_id', $saved->id)->sole();
    $transport = new ArrayTransport;
    if ($failure === 'crash') {
        // The provider accepted, then the worker died before completion: the lease expires with no outcome recorded.
        Mail::mailer()->setSymfonyTransport($transport);
        app(ClaimNotificationDelivery::class)($oneOffDelivery->id);
        $transport->send(new Email()->from('a@b.test')->to('boss@client.test')->subject('accepted')->text('x'));
    } else {
        // Rendering throws before the provider is called; the job fails and the lease expires.
        DB::table('client_message_attachments')->insert(['event_id' => $sent['event_id'], 'disk' => 'local', 'path' => 'client-messages/missing.csv',
            'bytes' => 1, 'rows' => 1, 'sha256' => str_repeat('0', 64), 'created_at' => CarbonImmutable::now()]);
        DB::table('events')->where('id', $sent['event_id'])->update(['payload' => DB::raw("jsonb_set(payload, '{attachment_filename}', '\"x.csv\"')")]);
        Mail::mailer()->setSymfonyTransport($transport);
        expect(fn () => app(SendNotificationDelivery::class)($oneOffDelivery->id))->toThrow(UnableToReadFile::class);
    }
    $this->travel(5)->minutes();
    Mail::mailer()->setSymfonyTransport($transport);
    expect(app(SendNotificationDelivery::class)($oneOffDelivery->id))->toBeNull();
    $oneOffDelivery->refresh();
    expect([$oneOffDelivery->attempts, $oneOffDelivery->suppressed, $oneOffDelivery->suppression_reason])->toBe([1, true, 'one_off_attempted'])
        ->and($oneOff->refresh()->removed_reason)->toBe('archived')
        ->and(array_map(fn ($sent): string => $sent->getOriginalMessage()->getTo()[0]->getAddress(), $transport->messages()->all()))
        ->toBe($failure === 'crash' ? ['boss@client.test'] : []);
    // The history shows the attempt as not delivered, with a reason and Send again for the one-off address.
    $row = collect($this->getJson(route('clients.messages.index', $client))->assertOk()->json('rows'))->firstWhere('id', $oneOffDelivery->id);
    expect($row['state'])->toBe('failed')->and($row['reason'])->toBe('The single attempt to this address did not finish; it will not be tried again.')
        ->and($row['resend']['one_off'])->toBe('boss@client.test');
    // The saved channel's failed send keeps its normal retries.
    DB::table('client_message_attachments')->where('event_id', $sent['event_id'])->delete();
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('RCPT TO:<office@client.test>', 451));
    app(SendNotificationDelivery::class)($savedDelivery->id);
    $savedDelivery->refresh();
    expect([$savedDelivery->state, $savedDelivery->suppressed])->toBe([DeliveryState::Failed, false])->and($saved->refresh()->removed_at)->toBeNull();
})->with(['crash', 'exception']);

it('sends an incident update in the client language without app links', function (): void {
    $client = Client::factory()->create(['locale' => 'en', 'timezone' => 'UTC']);
    $office = clientMessageChannel($client, 'office@client.test');
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://www.client.test']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => CarbonImmutable::parse('2026-10-28T20:00:00Z')]);
    $this->actingAs(clientMessageUser('Owner', ['name' => 'Ola Owner']));
    $sent = $this->postJson(route('clients.messages.store', $client), ['kind' => 'incident', 'design' => 'branded', 'incident_id' => $incident->id, 'channel_ids' => [$office->id]],
        ['Idempotency-Key' => 'inc'])->assertCreated()->json();
    $mails = clientMessageDeliver($sent['event_id']);
    expect($mails[0]->getSubject())->toBe('Problem on www.client.test: Uptime')
        ->and($mails[0]->getHtmlBody())->toContain('We noticed a problem on www.client.test', '28 Oct 2026 at 20:00', 'Kind regards, Ola Owner')
        ->and($mails[0]->getHtmlBody())->not->toContain('href="http')
        ->and(clientMessageFiles($mails[0]))->toBe([]);
});

it('limits messages per client with 429 and Retry-After', function (): void {
    $client = Client::factory()->create();
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 1);
    $this->actingAs(clientMessageUser('Owner'));
    for ($i = 1; $i <= 10; $i++) {
        $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]], ['Idempotency-Key' => "k{$i}"])->assertCreated();
    }
    $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]], ['Idempotency-Key' => 'k11'])
        ->assertTooManyRequests()->assertHeader('Retry-After', '86400');
});

it('lists what the client was sent, newest first, with sender, address, outcome and a resend for a failed one-off', function (): void {
    $owner = clientMessageUser('Owner', ['name' => 'Ola Owner']);
    $client = Client::factory()->create(['locale' => 'en']);
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 2);
    $this->actingAs($owner);
    $first = $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id], 'attach' => true],
        ['Idempotency-Key' => 'h1'])->assertCreated()->json();
    clientMessageDeliver($first['event_id']);
    $second = $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [], 'one_off' => 'boss@client.test', 'note' => 'FYI'],
        ['Idempotency-Key' => 'h2'])->assertCreated()->json();
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('RCPT TO:<boss@client.test>', 451));
    app(SendNotificationDelivery::class)($second['delivery_ids'][0]);

    $page = $this->getJson(route('clients.messages.index', $client))->assertOk()->json();
    expect($page['can_send'])->toBeTrue()->and($page['next_before'])->toBeNull()
        ->and(array_map(fn (array $row): array => [$row['subject'], $row['origin'], $row['actor'], $row['address'], $row['state'], $row['attachment']], $page['rows']))->toBe([
            ['Broken links on shop.example.test: 2 found', 'manual', 'Ola Owner', 'boss@client.test', 'failed', false],
            ['Broken links on shop.example.test: 2 found', 'manual', 'Ola Owner', 'office@client.test', 'accepted', true],
        ])
        ->and($page['rows'][0]['reason'])->not->toBeNull()
        ->and($page['rows'][0]['resend'])->toBe(['kind' => 'findings', 'site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => null, 'note' => 'FYI', 'attach' => false, 'design' => 'branded', 'one_off' => 'boss@client.test'])
        ->and($page['rows'][1]['resend'])->toBeNull();

    // A migrated contact without a name carries its email as the label; Members see neither the address nor that label.
    $office->update(['label' => 'office@client.test']);
    expect(array_column($this->getJson(route('clients.messages.index', $client))->json('rows'), 'channel'))->toContain('office@client.test');
    $member = clientMessageUser('Member');
    $response = $this->actingAs($member)->getJson(route('clients.messages.index', $client))->assertOk();
    $rows = $response->json();
    expect($rows['can_send'])->toBeFalse()->and(array_column($rows['rows'], 'address'))->toBe([null, null])
        ->and(array_column($rows['rows'], 'channel'))->toBe(['Hidden recipient', 'Hidden recipient'])
        ->and($response->getContent())->not->toContain('office@client.test')->not->toContain('boss@client.test')
        ->and(array_column($rows['rows'], 'resend'))->toBe([null, null]);
    $this->actingAs($member)->postJson(route('clients.messages.preview', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => []])->assertForbidden();
});

it('pages every channel of the client newest first by the before cursor, leaving out digests and other clients', function (): void {
    $client = Client::factory()->create();
    $channels = [clientMessageChannel($client, 'a@client.test'), clientMessageChannel($client, 'b@client.test'), clientMessageChannel($client, 'c@client.test')];
    $other = clientMessageChannel(Client::factory()->create(), 'x@other.test');
    $site = Site::factory()->create(['client_id' => $client->id]);
    $expected = [];
    for ($i = 0; $i < 60; $i++) {
        // Uneven: channel a gets most rows, so one channel's newest rows interleave with the others'.
        $channel = $channels[$i % 5 === 0 ? 1 : ($i % 7 === 0 ? 2 : 0)];
        $event = Event::factory()->create(['site_id' => $site->id, 'type' => 'check.result_changed', 'from_result' => 'succeeded', 'to_result' => 'warning', 'payload' => []]);
        $expected[] = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()])->id;
        $noise = Event::factory()->create(['site_id' => $site->id, 'type' => 'check.result_changed', 'from_result' => 'succeeded', 'to_result' => 'warning', 'payload' => []]);
        Delivery::query()->create(['event_id' => $noise->id, 'destination_id' => $i % 2 === 0 ? $other->id : $channel->id, 'digest' => $i % 2 !== 0,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    }
    $this->actingAs(clientMessageUser('Owner'));

    $seen = [];
    $before = null;
    $pages = 0;
    do {
        $page = $this->getJson(route('clients.messages.index', ['client' => $client, 'before' => $before]))->assertOk()->json();
        $seen = [...$seen, ...array_column($page['rows'], 'id')];
        $before = $page['next_before'];
        $pages++;
    } while ($before !== null);

    expect($pages)->toBe(3)->and($seen)->toBe(array_reverse($expected));
});

it('sweeps only orphan attachment files older than a day', function (): void {
    $client = Client::factory()->create();
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 1);
    $this->actingAs(clientMessageUser('Owner'))->postJson(route('clients.messages.store', $client),
        ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id], 'attach' => true], ['Idempotency-Key' => 's'])->assertCreated();
    $kept = DB::table('client_message_attachments')->value('path');
    // File times are the disk's, not the frozen clock's: set them explicitly around the 24-hour cutoff.
    Storage::disk('local')->put('client-messages/orphan-old.csv', 'x');
    touch(Storage::disk('local')->path('client-messages/orphan-old.csv'), CarbonImmutable::now()->subHours(25)->getTimestamp());
    Storage::disk('local')->put('client-messages/orphan-new.csv', 'x');
    touch(Storage::disk('local')->path('client-messages/orphan-new.csv'), CarbonImmutable::now()->subHours(23)->getTimestamp());
    touch(Storage::disk('local')->path($kept), CarbonImmutable::now()->subHours(48)->getTimestamp());
    $this->artisan('heptapod:client-messages:sweep')->expectsOutput('Deleted orphan client message attachments: 1')->assertSuccessful();
    expect(Storage::disk('local')->files('client-messages'))->toEqualCanonicalizing([$kept, 'client-messages/orphan-new.csv']);
});

it('replays an identical retry after a newer run or after a chosen channel was removed', function (): void {
    $client = Client::factory()->create();
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 2);
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]];
    $this->actingAs(clientMessageUser('Owner'));
    $first = $this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'stable'])->assertCreated()->json();

    $newer = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'broken_links.failed',
        'started_at' => CarbonImmutable::parse('2026-10-28T22:00:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T22:02:00Z')]);
    $check->update(['latest_run_id' => $newer->id, 'latest_run_ended_at' => $newer->ended_at]);
    $this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'stable'])->assertOk()->assertJsonPath('event_id', $first['event_id']);

    $office->update(['removed_at' => CarbonImmutable::now(), 'removed_reason' => 'archived']);
    $this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'stable'])->assertOk()->assertJsonPath('replayed', true);
    // The same key with different content is still a conflict, and another client never sees this message.
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'note' => 'changed'], ['Idempotency-Key' => 'stable'])->assertConflict();
    $other = Client::factory()->create();
    $this->postJson(route('clients.messages.store', $other), [...$payload, 'channel_ids' => []], ['Idempotency-Key' => 'stable'])->assertConflict();
    expect(Event::query()->where('type', 'client_message.findings')->count())->toBe(1);
});

it('counts the client quota from the messages sent in the last 24 hours', function (): void {
    $client = Client::factory()->create();
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 1);
    $owner = clientMessageUser('Owner');
    $this->actingAs($owner);
    for ($i = 1; $i <= 10; $i++) {
        $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]], ['Idempotency-Key' => "q{$i}"])->assertCreated();
    }
    // The window slides with the messages: an hour before the oldest leaves it, the client is still full.
    $this->travel(23)->hours();
    $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]], ['Idempotency-Key' => 'q11'])
        ->assertTooManyRequests()->assertHeader('Retry-After', '3600');
    $this->travel(1)->hours();
    $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]], ['Idempotency-Key' => 'q12'])->assertCreated();
});

it('names unverifiable broken-links rows links, not pages', function (): void {
    $client = Client::factory()->create(['locale' => 'de']);
    $check = clientMessageFindings($client, 1);
    DB::table('crawl_findings')->insert(['snapshot_id' => DB::table('crawl_snapshots')->where('check_id', $check->id)->value('id'),
        'crawled_url' => 'https://shop.example.test/blocked', 'relative_crawled_url' => '/blocked', 'status_code' => 403, 'error_message' => 'bot_protection',
        'type' => 'link', 'internal' => true]);
    $this->actingAs(clientMessageUser('Owner'));
    $html = $this->postJson(route('clients.messages.preview', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => []])->assertOk()->json('html');
    expect($html)->toContain('1 Link konnte nicht geprüft werden')->not->toContain('Seite konnte');
});

it('words confirmed findings and unverifiable pages separately, with a classification column and a calm report tone', function (): void {
    $client = Client::factory()->create(['locale' => 'en']);
    $office = clientMessageChannel($client, 'office@client.test');
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://mixed.example.test']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::MixedContent, 'latest_run_result' => CheckResult::Failed]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'mixed_content.failed',
        'started_at' => CarbonImmutable::parse('2026-10-28T21:38:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T21:40:00Z')]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $snapshot = DB::table('crawl_snapshots')->insertGetId(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000009', 'run_ended_at' => $run->ended_at, 'collected_at' => $run->ended_at, 'settings' => '{}',
        'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]', 'discovery_complete' => true,
        'evaluated_finding_ids' => '[]', 'site_url' => $site->url, 'target_fingerprint' => str_repeat('a', 64), 'classification_policy_version' => null]);
    DB::table('mixed_content_findings')->insert(['snapshot_id' => $snapshot, 'element_name' => 'img', 'mixed_content_url' => 'http://cdn.example.test/logo.png',
        'source_url' => 'https://mixed.example.test/', 'found_on_url' => 'https://mixed.example.test/']);
    foreach (['/members', '/shop'] as $path) {
        DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => 'https://mixed.example.test'.$path, 'relative_crawled_url' => $path,
            'status_code' => 403, 'error_message' => 'bot_protection', 'found_on_url' => 'https://mixed.example.test/', 'type' => 'link', 'internal' => true]);
    }
    $this->actingAs(clientMessageUser('Owner'));
    $sent = $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id], 'attach' => true],
        ['Idempotency-Key' => 'classes'])->assertCreated()->json();
    $mail = clientMessageDeliver($sent['event_id'])[0];
    $html = $mail->getHtmlBody();
    expect($mail->getSubject())->toBe('Insecure content on mixed.example.test: 1 found')
        ->and($html)->toContain('we found 1 resource that loads without encryption', '2 pages could not be checked')
        ->and($html)->not->toContain('3 resources')
        // A report the sender chose to send has no alert-red bar.
        ->and($html)->not->toContain('class="bar-down"');
    $client->update(['locale' => 'de']);
    $german = $this->postJson(route('clients.messages.preview', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => []])->assertOk()->json();
    expect($german['subject'])->toBe('Unsichere Inhalte auf mixed.example.test: 1 gefunden')
        ->and($german['html'])->toContain('1 Inhalt gefunden, der unverschlüsselt geladen wird', '2 Seiten konnten nicht geprüft werden');
    $csv = Storage::disk('local')->get(DB::table('client_message_attachments')->where('event_id', $sent['event_id'])->value('path'));
    expect(explode("\r\n", trim($csv)))->toBe([
        "\xEF\xBB\xBFAddress,Result,Classification,\"Found on\",\"Link text\"",
        'http://cdn.example.test/logo.png,"Mixed content",Finding,https://mixed.example.test/,',
        'https://mixed.example.test/members,"Not verifiable: the site blocks automated checks (bot protection)","Could not be checked",https://mixed.example.test/,',
        'https://mixed.example.test/shop,"Not verifiable: the site blocks automated checks (bot protection)","Could not be checked",https://mixed.example.test/,',
    ]);
});

it('shows the frozen message and its CSV from the history, only for the client it was sent to', function (): void {
    $client = Client::factory()->create(['locale' => 'de']);
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 2);
    $this->actingAs($owner = clientMessageUser('Owner'));
    $sent = $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id],
        'note' => 'Wie besprochen.', 'attach' => true], ['Idempotency-Key' => 'view'])->assertCreated()->json();
    $csv = Storage::disk('local')->get(DB::table('client_message_attachments')->where('event_id', $sent['event_id'])->value('path'));
    // Later findings do not change what the history shows: it renders the frozen payload.
    DB::table('crawl_findings')->update(['crawled_url' => 'https://shop.example.test/changed']);

    $message = $this->getJson(route('clients.messages.show', [$client, $sent['event_id']]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    expect($message['subject'])->toBe('Defekte Links auf shop.example.test: 2 gefunden')
        ->and($message['html'])->toContain('Wie besprochen.', 'missing-1')->not->toContain('changed')
        ->and($message['attachment'])->toBe(['filename' => 'defekte-links-shop-example-test-2026-10-28.csv', 'rows' => 2]);
    $download = $this->get(route('clients.messages.attachment', [$client, $sent['event_id']]))->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertDownload('defekte-links-shop-example-test-2026-10-28.csv');
    expect($download->streamedContent())->toBe($csv);

    // Members read the history; another client's page never reaches this message.
    $this->actingAs(clientMessageUser('Member'))->getJson(route('clients.messages.show', [$client, $sent['event_id']]))->assertOk();
    $other = Client::factory()->create();
    $this->actingAs($owner)->getJson(route('clients.messages.show', [$other, $sent['event_id']]))->assertNotFound();
    $this->get(route('clients.messages.attachment', [$other, $sent['event_id']]))->assertNotFound();
    $restricted = clientMessageUser('Member', ['client_scope' => ClientScope::Restricted]);
    $restricted->clients()->attach($other->id);
    $this->actingAs($restricted)->getJson(route('clients.messages.show', [$client, $sent['event_id']]))->assertNotFound();
    $this->get(route('clients.messages.attachment', [$client, $sent['event_id']]))->assertNotFound();
});

it('keys a one-off address by exactly the address it sends to', function (): void {
    $client = Client::factory()->create();
    $check = clientMessageFindings($client, 1);
    $this->actingAs(clientMessageUser('Owner'));
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => []];
    $first = $this->postJson(route('clients.messages.store', $client), [...$payload, 'one_off' => ' Alice@Client.TEST '], ['Idempotency-Key' => 'case'])->assertCreated()->json();
    // The domain is case-insensitive and whitespace is not part of an address: the same recipient replays.
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'one_off' => 'Alice@client.test'], ['Idempotency-Key' => 'case'])
        ->assertOk()->assertJsonPath('event_id', $first['event_id']);
    // A different local part is a different mailbox: the key cannot stand for it.
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'one_off' => 'alice@client.test'], ['Idempotency-Key' => 'case'])->assertConflict();
    expect(Delivery::query()->where('event_id', $first['event_id'])->sole()->recipient_snapshot['address'])->toBe('Alice@client.test')
        ->and(NotificationDestination::query()->where('client_id', $client->id)->sole()->destinationConfig()->string('email'))->toBe('Alice@client.test');
});

it('says a sitemap check stopped at a limit instead of counting it as an address that could not be checked', function (string $locale, string $limited, string $notCounted): void {
    $client = Client::factory()->create(['locale' => $locale]);
    $office = clientMessageChannel($client, 'office@client.test');
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://maps.example.test']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Sitemap, 'latest_run_result' => CheckResult::Failed]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'sitemap.failed',
        'started_at' => CarbonImmutable::parse('2026-10-28T21:38:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T21:40:00Z')]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    DB::table('sitemap_snapshots')->insert(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id, 'claim_token' => '00000000-0000-4000-8000-000000000010',
        'run_ended_at' => $run->ended_at, 'created_at' => $run->ended_at, 'snapshot' => json_encode(['issues' => [
            ['url' => 'https://maps.example.test/gone', 'type' => 'causes.http.not_found', 'kind' => 'http_status', 'status' => 404],
            ['url' => 'https://maps.example.test/sitemap.xml', 'type' => 'sitemap.issue.url_limit'],
        ], 'not_verifiable' => []], JSON_THROW_ON_ERROR)]);
    $this->actingAs(clientMessageUser('Owner'));
    $sent = $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id], 'attach' => true],
        ['Idempotency-Key' => 'limit-'.$locale])->assertCreated()->json();
    $html = clientMessageDeliver($sent['event_id'])[0]->getHtmlBody();
    expect($html)->toContain($limited)->not->toContain($notCounted);
    $csv = Storage::disk('local')->get(DB::table('client_message_attachments')->where('event_id', $sent['event_id'])->value('path'));
    expect($csv)->toContain($locale === 'de' ? ',Prüfgrenze,' : ',"Check limit",');
})->with([
    ['en', 'The check stopped at a limit, so not every address in the sitemap was checked.', 'could not be checked'],
    ['de', 'Die Prüfung hat ein Limit erreicht, daher wurden nicht alle Adressen der Sitemap geprüft.', 'nicht geprüft werden'],
]);

it('does not call a sitemap that only hit a limit addresses that could not be checked', function (): void {
    $client = Client::factory()->create(['locale' => 'en']);
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://only-limit.example.test']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Sitemap, 'latest_run_result' => CheckResult::Failed]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'sitemap.failed',
        'started_at' => CarbonImmutable::parse('2026-10-28T21:38:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T21:40:00Z')]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    DB::table('sitemap_snapshots')->insert(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id, 'claim_token' => '00000000-0000-4000-8000-000000000011',
        'run_ended_at' => $run->ended_at, 'created_at' => $run->ended_at, 'snapshot' => json_encode(['issues' => [
            ['url' => 'https://only-limit.example.test/sitemap.xml', 'type' => 'sitemap.issue.time_limit'],
        ], 'not_verifiable' => []], JSON_THROW_ON_ERROR)]);
    $this->actingAs(clientMessageUser('Owner'));
    $preview = $this->postJson(route('clients.messages.preview', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => []])->assertOk()->json();
    expect($preview['subject'])->toBe('Sitemap check on only-limit.example.test stopped at a limit')
        ->and($preview['html'])->toContain('not every address in the sitemap was checked')->not->toContain('could not be checked');
});

it('names an automatic alert by its category and site and says why one was not sent', function (): void {
    $client = Client::factory()->create(['locale' => 'en']);
    $channel = clientMessageChannel($client, 'office@client.test');
    $site = Site::factory()->create(['client_id' => $client->id, 'friendly_name' => 'API']);
    $sent = Event::factory()->create(['site_id' => $site->id, 'type' => 'check.result_changed', 'from_result' => 'succeeded', 'to_result' => 'failed', 'payload' => []]);
    Delivery::query()->create(['event_id' => $sent->id, 'destination_id' => $channel->id, 'category' => 'outage',
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    $held = Event::factory()->create(['site_id' => $site->id, 'type' => 'check.result_changed', 'from_result' => 'succeeded', 'to_result' => 'warning', 'payload' => []]);
    Delivery::query()->create(['event_id' => $held->id, 'destination_id' => $channel->id, 'category' => 'warning',
        'suppressed' => true, 'suppression_reason' => 'monitoring_stopped', 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    $rows = $this->actingAs(clientMessageUser('Owner'))->getJson(route('clients.messages.index', $client))->assertOk()->json('rows');

    expect(array_map(fn (array $row): array => [$row['subject'], $row['state'], $row['reason']], $rows))->toBe([
        ['Warning · API', 'suppressed', 'Monitoring stopped'],
        ['Outage · API', 'pending', null],
    ]);
});

it('keeps a valid 2000-character note intact in the preview and the sent mail', function (string $locale, string $unit): void {
    $client = Client::factory()->create(['locale' => $locale, 'timezone' => 'UTC']);
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 2);
    // Exactly the 2000 characters the note validation allows; multi-byte units make it far more than 1000 bytes.
    $note = mb_substr(str_repeat($unit, 2000), 0, 1999).'!';
    expect(mb_strlen($note))->toBe(2000);
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id], 'note' => $note];
    $this->actingAs(clientMessageUser('Owner', ['name' => 'Ola Owner']));

    $preview = $this->postJson(route('clients.messages.preview', $client), $payload)->assertOk()->json();
    expect(html_entity_decode($preview['html'], ENT_QUOTES))->toContain($note);
    $sent = $this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'long-note-'.$locale])->assertCreated()->json();
    $mail = clientMessageDeliver($sent['event_id'])[0];
    expect($mail->getTextBody())->toContain($note)
        ->and(html_entity_decode($mail->getHtmlBody(), ENT_QUOTES))->toContain($note);
})->with([
    'EN ASCII' => ['en', 'Please review. '],
    'DE ASCII' => ['de', 'Bitte prüfen. '],
    'EN Unicode' => ['en', 'ä€😀 '],
    'DE Unicode' => ['de', 'Grüße € 🙂 '],
]);

it('refuses a CSV over 5 MB with the size message before anything is stored or sent', function (string $locale, string $message): void {
    $client = Client::factory()->create(['locale' => $locale]);
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 0);
    // 5,300 rows of about 1 KB each: well under the 10,000-row limit, over the 5 MiB byte limit.
    DB::statement("INSERT INTO crawl_findings (snapshot_id, crawled_url, relative_crawled_url, status_code, found_on_url, link_text, type, internal)
        SELECT ?, 'https://shop.example.test/missing-'||g, '/missing-'||g, 404, 'https://shop.example.test/catalog', repeat('x', 1000), 'link', true
        FROM generate_series(1, 5300) g", [DB::table('crawl_snapshots')->where('check_id', $check->id)->value('id')]);
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id], 'attach' => true];
    $this->actingAs(clientMessageUser('Owner', ['locale' => $locale]));

    $this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'too-large'])
        ->assertUnprocessable()->assertJsonPath('errors.attach.0', $message);
    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(Event::query()->where('type', 'client_message.findings')->exists())->toBeFalse();
    // Without the attachment the same findings send.
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'attach' => false], ['Idempotency-Key' => 'no-attachment'])->assertCreated();
})->with([
    'EN' => ['en', 'The CSV file is larger than 5 MB; send it without the attachment.'],
    'DE' => ['de', 'Die CSV-Datei ist größer als 5 MB; senden Sie die Nachricht ohne Anhang.'],
]);

it('accepts a CSV of exactly 5 MB and refuses one byte more or a single row over the limit', function (): void {
    $client = Client::factory()->create(['locale' => 'en']);
    $office = clientMessageChannel($client, 'office@client.test');
    $check = clientMessageFindings($client, 1);
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id], 'attach' => true];
    $this->actingAs(clientMessageUser('Owner'));
    $linkText = fn (int $length) => DB::table('crawl_findings')->update(['link_text' => str_repeat('x', $length)]);
    $stored = fn (int $eventId): object => DB::table('client_message_attachments')->where('event_id', $eventId)->sole();

    // A one-character link text measures the rest of the file; plain letters need no quoting, so each one adds a byte.
    $linkText(1);
    $small = $stored($this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'measure'])->assertCreated()->json('event_id'));
    $atLimit = 1 + 5 * 1024 * 1024 - $small->bytes;

    $linkText($atLimit);
    $exact = $stored($this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'exact'])->assertCreated()->json('event_id'));
    expect($exact->bytes)->toBe(5 * 1024 * 1024)->and(strlen(Storage::disk('local')->get($exact->path)))->toBe(5 * 1024 * 1024);

    $files = Storage::disk('local')->allFiles();
    $events = Event::query()->where('type', 'client_message.findings')->count();
    foreach (['one-byte-over' => $atLimit + 1, 'one-row-of-6-mb' => 6 * 1024 * 1024] as $case => $length) {
        $linkText($length);
        $this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => $case])
            ->assertUnprocessable()->assertJsonPath('errors.attach.0', 'The CSV file is larger than 5 MB; send it without the attachment.');
        expect(Storage::disk('local')->allFiles())->toBe($files)
            ->and(Event::query()->where('type', 'client_message.findings')->count())->toBe($events);
    }

    // Over both limits, the row limit is what the sender reads.
    DB::statement("INSERT INTO crawl_findings (snapshot_id, crawled_url, relative_crawled_url, status_code, found_on_url, link_text, type, internal)
        SELECT snapshot_id, crawled_url||'-'||g, relative_crawled_url||'-'||g, 404, found_on_url, 'x', 'link', true
        FROM crawl_findings CROSS JOIN generate_series(1, 10000) g");
    $this->postJson(route('clients.messages.store', $client), $payload, ['Idempotency-Key' => 'both-limits'])
        ->assertUnprocessable()->assertJsonPath('errors.attach.0', 'The list has more than 10000 rows; it cannot be attached.');
    expect(Storage::disk('local')->allFiles())->toBe($files);
});

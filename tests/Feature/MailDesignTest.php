<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\SendNotificationDelivery;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\RecipientKind;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Invitation;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\Site;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Heptapod\Notifications\TeamInvitation;
use Heptapod\Settings\InstanceSettings;
use Heptapod\SystemHealth\SystemIssueKind;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Part\DataPart;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T09:15:00Z'));
    config(['app.url' => 'https://monitor.example.test', 'mail.default' => 'smtp']);
    URL::forceRootUrl('https://monitor.example.test');
    $settings = app(InstanceSettings::class);
    $settings->name = 'Golden Monitor';
    $settings->timezone = 'Europe/Berlin';
    $settings->mail_design = MailDesign::Plain;
    $settings->save();
    Storage::fake('local');
    Queue::fake();
});

function mailDesignTransport(): ArrayTransport
{
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);

    return $transport;
}

function mailDesignLast(ArrayTransport $transport): Email
{
    $mail = $transport->messages()->last()?->getOriginalMessage();

    return $mail instanceof Email ? $mail : throw new LogicException('No email was sent.');
}

/** @return list<string> the content ids of the mail's inline parts */
function mailDesignInlineParts(Email $mail): array
{
    return array_values(array_map(static fn (DataPart $part): string => $part->getContentId(),
        array_filter($mail->getAttachments(), static fn (DataPart $part): bool => $part->getDisposition() === 'inline')));
}

/** Message-ID, Date and multipart boundaries are random per send; ids come from shared sequences. */
function mailDesignGolden(Email $mail): string
{
    $headers = [];
    foreach (['To', 'Reply-To', 'Subject'] as $name) {
        $header = $mail->getHeaders()->get($name);
        if ($header instanceof HeaderInterface) {
            $headers[] = $name.': '.$header->getBodyAsString();
        }
    }
    $parts = array_map(static fn (DataPart $part): string => $part->getDisposition().' '.$part->getMediaType().'/'.$part->getMediaSubtype().' '.($part->getFilename() ?? ''),
        $mail->getAttachments());
    $text = implode("\n", $headers)."\n-- PARTS --\n".implode("\n", $parts)."\n-- PLAIN --\n".$mail->getTextBody()."\n-- HTML --\n".$mail->getHtmlBody();

    return preg_replace(['#/(incidents|sites|checks|notifications/channels)/[1-9][0-9]*#', '/(?<=[ ]|\\/)#[1-9][0-9]*\b/'], ['/$1/{id}', '#{id}'], $text)
        ?? throw new LogicException('Could not normalise the mail golden.');
}

function mailDesignChannel(string $locale): NotificationDestination
{
    return NotificationDestination::factory()->create(['label' => 'Golden inbox', 'channel' => 'mail', 'locale' => $locale,
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'ops@golden.example'])]);
}

function mailDesignSite(string $path): Site
{
    $client = Client::factory()->create(['name' => 'Golden client', 'timezone' => 'Europe/Berlin', 'accent_color' => '#336699']);

    return Site::factory()->for($client)->create(['friendly_name' => 'Golden shop', 'url' => 'https://shop.golden.example/'.$path]);
}

function mailDesignSend(NotificationDestination $channel, Event $event, ArrayTransport $transport): Email
{
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    app()->call(new SendNotification($delivery->id)->handle(...));
    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);

    return mailDesignLast($transport);
}

/** A failed broken-links check with $count findings on the client's own host and one on another host. */
function mailDesignFindings(Client $client, int $count): Check
{
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://shop.example.test']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::BrokenLinks, 'latest_run_result' => CheckResult::Failed]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'broken_links.failed',
        'started_at' => CarbonImmutable::parse('2026-10-28T21:38:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T21:40:00Z')]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $snapshot = DB::table('crawl_snapshots')->insertGetId(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000003', 'run_ended_at' => $run->ended_at, 'collected_at' => $run->ended_at,
        'settings' => '{}', 'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => true, 'evaluated_finding_ids' => '[]', 'site_url' => $site->url, 'target_fingerprint' => str_repeat('a', 64),
        'classification_policy_version' => null]);
    for ($i = 1; $i <= $count; $i++) {
        DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => "https://shop.example.test/products/old-{$i}?ref=nav", 'relative_crawled_url' => "/products/old-{$i}",
            'status_code' => 404, 'found_on_url' => 'https://shop.example.test/catalog', 'link_text' => "Item {$i}", 'type' => 'link', 'internal' => true]);
    }
    DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => 'https://partner.example.test/gone', 'relative_crawled_url' => 'https://partner.example.test/gone',
        'status_code' => 410, 'found_on_url' => 'https://shop.example.test/about', 'link_text' => 'Partner', 'type' => 'link', 'internal' => false]);

    return $check;
}

function mailDesignClientChannel(Client $client, string $address): NotificationDestination
{
    $channel = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $client->id, 'user_id' => null,
        'channel' => 'mail', 'label' => 'Office', 'locale' => null, 'enabled' => true,
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => $address])]);
    NotificationPreference::writeInitialGrid($channel->id, NotificationPreference::defaults(RecipientKind::Client));

    return $channel;
}

/** @return list<Email> */
function mailDesignDeliverClientMessage(int $eventId, ArrayTransport $transport): array
{
    foreach (Delivery::query()->where('event_id', $eventId)->orderBy('id')->pluck('id') as $id) {
        app(SendNotificationDelivery::class)($id);
    }

    return array_map(static fn ($sent) => $sent->getOriginalMessage(), $transport->messages()->all());
}

it('localizes XML line numbers in mail evidence and exported result sentences', function (string $locale, string $sentence, string $rawSentence): void {
    $transport = mailDesignTransport();
    $channel = mailDesignChannel($locale);
    $check = Check::factory()->for(mailDesignSite('sitemap'))->create(['type' => CheckKey::Sitemap]);
    $url = 'https://shop.golden.example/sitemap.xml';
    $snapshot = ['checkUrl' => $url, 'totalIssuesCount' => 1, 'totalUrlCount' => 0, 'hasIssues' => true,
        'issues' => [['url' => $url, 'type' => 'sitemap.issue.invalid', 'xml_reason' => 'syntax', 'xml_line' => 1234]],
        'sitemapIndexes' => [], 'sitemaps' => []];
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'sitemap.summary.invalid_xml',
        'summary_params' => ['path' => '/sitemap.xml', 'xml_reason' => 'syntax', 'xml_line' => 1234], 'evidence' => $snapshot,
        'started_at' => now()->subSecond(), 'ended_at' => now()]);
    DB::table('sitemap_snapshots')->insert(['site_id' => $check->site_id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000012', 'run_ended_at' => $run->ended_at,
        'created_at' => $run->ended_at, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id,
        'evidence' => ['summary' => $run->summary, 'summary_params' => $run->summary_params, 'evidence' => $snapshot,
            'run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\TH:i:s.uP')]]);
    $event = Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed]);
    // The destination's language, not the worker's current language, owns the mail evidence.
    app()->setLocale($locale === 'en' ? 'de' : 'en');

    $mail = mailDesignSend($channel, $event, $transport);

    expect($mail->getTextBody())->toContain($sentence)->not->toContain($rawSentence);
    expect($mail->getHtmlBody())->toContain($sentence)->not->toContain($rawSentence);
    $owner = User::factory()->owner()->create(['locale' => $locale]);
    $export = $this->actingAs($owner)->get(route('sites.checks.findings', ['site' => $check->site_id, 'check' => $check->id,
        'run' => $run->id, 'observed_at' => $run->ended_at->toIso8601String()]))->assertOk()->streamedContent();
    $row = str_getcsv(explode("\r\n", $export)[1], ',', '"', '');
    expect($row[1])->toBe($sentence);
    expect($run->refresh()->summary_params['xml_line'])->toBe(1234);
})->with([
    ['en', 'The sitemap is not valid XML: syntax error on line 1,234.', 'syntax error on line 1234.'],
    ['de', 'Die Sitemap ist kein gültiges XML: Syntaxfehler in Zeile 1.234.', 'Syntaxfehler in Zeile 1234.'],
]);

it('renders every alert, system and client mail kind in the Plain design through the real send path', function (string $locale): void {
    $transport = mailDesignTransport();
    $channel = mailDesignChannel($locale);
    $golden = [];

    $opened = Check::factory()->for(mailDesignSite('opened'))->create(['type' => CheckKey::Uptime]);
    $incident = Incident::factory()->for($opened)->create(['site_id' => $opened->site_id, 'opened_at' => now()->subMinutes(4),
        'first_failure_at' => now()->subMinutes(5), 'evidence' => ['summary' => 'causes.http', 'summary_params' => ['status' => 503],
            'evidence' => [], 'metrics' => null, 'error' => null, 'run_ended_at' => now()->subMinutes(4)->format('Y-m-d\TH:i:s.uP')]]);
    $openedEvent = Event::factory()->create(['site_id' => $opened->site_id, 'check_id' => $opened->id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed]);
    $golden['alert opened'] = mailDesignGolden(mailDesignSend($channel, $openedEvent, $transport));

    // A recovery only goes to channels whose opening was sent; the opening above was.
    $run = CheckRun::factory()->for($opened)->create(['started_at' => now()->subSecond(), 'ended_at' => now(), 'summary' => 'uptime.succeeded']);
    $incident->update(['resolved_at' => now()]);
    $recovered = Event::factory()->create(['site_id' => $opened->site_id, 'check_id' => $opened->id, 'incident_id' => $incident->id, 'type' => 'incident.resolved',
        'cause' => 'failed', 'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded,
        'payload' => ['run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\TH:i:s.uP')]]);
    $golden['recovery'] = mailDesignGolden(mailDesignSend($channel, $recovered, $transport));

    $sourceIds = [];
    foreach (['digest-a', 'digest-b'] as $path) {
        $warning = Event::factory()->create(['check_id' => Check::factory()->for(mailDesignSite($path))->create(['type' => CheckKey::Performance])->id,
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
        $sourceIds[] = Delivery::query()->create(['event_id' => $warning->id, 'destination_id' => $channel->id, 'digest' => true,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()])->id;
    }
    $digest = Event::factory()->create(['check_id' => null, 'site_id' => null, 'type' => 'notification.digest', 'from_result' => null, 'to_result' => null,
        'payload' => ['count' => 2, 'sites' => 'Golden shop, Golden shop', 'remaining_sites' => 0, 'delivery_ids' => $sourceIds]]);
    $golden['digest'] = mailDesignGolden(mailDesignSend($channel, $digest, $transport));

    $certificate = Check::factory()->for(mailDesignSite('catch-up'))->create(['type' => CheckKey::CertificateHealth]);
    $open = Incident::factory()->for($certificate)->create(['site_id' => $certificate->site_id, 'opened_at' => now()->subHour()]);
    $catchUp = Event::factory()->create(['site_id' => $certificate->site_id, 'check_id' => $certificate->id, 'incident_id' => $open->id,
        'type' => 'maintenance.catch_up', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
        'payload' => ['uptime_episode' => false, 'count' => 2, 'check_keys' => ['certificate_health', 'uptime'], 'since' => '2026-10-30T08:15:00+00:00']]);
    DB::table('maintenance_catch_up_incidents')->insert(['event_id' => $catchUp->id, 'incident_id' => $open->id]);
    $golden['catch-up'] = mailDesignGolden(mailDesignSend($channel, $catchUp, $transport));

    DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', 'system')->update(['mode' => 'immediate']);
    $issue = SystemIssue::query()->create(['kind' => SystemIssueKind::BackupFailed, 'scope' => '', 'opened_at' => now()->subHour(),
        'details' => ['disk' => 'backups', 'failed_at' => '2026-10-30T08:15:00+00:00']]);
    $mailer = app(SystemIssueMailer::class);
    $golden['system issue'] = mailDesignGolden(mailDesignSend($channel, $mailer->event($issue, 'system.issue_opened', now(), []), $transport));
    $issue->update(['resolved_at' => now()]);
    $golden['system recovery'] = mailDesignGolden(mailDesignSend($channel, $mailer->event($issue, 'system.issue_resolved', now(), ['probe' => false]), $transport));

    // The channel test shows what the channel receives, so it follows the instance design.
    $owner = User::factory()->owner()->create(['name' => 'Golden Owner']);
    $this->actingAs($owner)->postJson(route('notification-channels.test', $channel))->assertOk()->assertJsonPath('status', 'sent');
    $golden['test send'] = mailDesignGolden(mailDesignLast($transport));

    $client = Client::factory()->create(['name' => 'Golden client', 'locale' => $locale, 'timezone' => 'Europe/Berlin']);
    $office = mailDesignClientChannel($client, 'office@client.test');
    $check = mailDesignFindings($client, 21);
    $sender = User::factory()->owner()->create(['name' => 'Anna Berger', 'email' => 'anna@studio.test']);
    $sent = $this->actingAs($sender)->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'plain', 'check_id' => $check->id,
        'channel_ids' => [$office->id], 'note' => 'Please look at these by Friday.', 'attach' => true], ['Idempotency-Key' => 'plain-findings'])->assertCreated()->json();
    mailDesignDeliverClientMessage($sent['event_id'], $transport);
    $golden['client findings with CSV'] = mailDesignGolden(mailDesignLast($transport));
    $clientIncident = Incident::factory()->for(Check::factory()->for(Site::factory()->for($client)->create(['url' => 'https://www.client.test']))->create(['type' => CheckKey::Uptime]))
        ->create(['first_failure_at' => CarbonImmutable::parse('2026-10-28T20:00:00Z')]);
    $sent = $this->postJson(route('clients.messages.store', $client), ['kind' => 'incident', 'design' => 'plain', 'incident_id' => $clientIncident->id,
        'channel_ids' => [$office->id]], ['Idempotency-Key' => 'plain-incident'])->assertCreated()->json();
    mailDesignDeliverClientMessage($sent['event_id'], $transport);
    $golden['client incident'] = mailDesignGolden(mailDesignLast($transport));

    foreach ($golden as $name => $text) {
        expect($text)->not->toContain('cid:', 'lockup', 'inline image/png', '<h1', 'class="btn-primary"', 'border-radius', 'background:', $name);
    }
    expect(implode("\n=====\n", array_map(static fn (string $name, string $text): string => $name."\n".$text, array_keys($golden), $golden)))->toMatchSnapshot();
})->with(['en', 'de']);

it('uses a neutral dash instead of stored initials for No client in branded mail', function (): void {
    $transport = mailDesignTransport();
    $settings = app(InstanceSettings::class);
    $settings->mail_design = MailDesign::Branded;
    $settings->save();
    $client = Client::query()->where('is_internal', true)->firstOrFail();
    $client->update(['name' => 'Internal', 'short_name' => 'CUSTOM', 'accent_color' => '#336699']);
    $check = Check::factory()->for(Site::factory()->for($client)->create())->create(['type' => CheckKey::Uptime]);
    $event = Event::factory()->for($check)->create(['from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);

    $mail = mailDesignSend(mailDesignChannel('en'), $event, $transport);

    expect($mail->getHtmlBody())->toContain('>—</td>', 'background:#e1e5e8;color:#5c6771;')->not->toContain('>CUSTOM</td>', '>I</td>');
});

it('attaches no inline image parts to a Plain mail and keeps the lockup and avatar on a Branded one', function (bool $internal): void {
    $transport = mailDesignTransport();
    $channel = mailDesignChannel('en');
    $site = $internal ? Site::factory()->for(Client::query()->where('is_internal', true)->firstOrFail())->create() : mailDesignSite('inline');
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    // A stored site icon the client uses as its avatar: Branded attaches it inline, Plain must not.
    $site = $check->site;
    $icon = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    Storage::disk()->put('site-icons/'.$site->id.'/'.str_repeat('b', 64).'.png', $icon);
    $site->forceFill(['icon_hash' => str_repeat('b', 64)])->save();
    $site->client->forceFill(['avatar_site_id' => $site->id])->save();
    $avatarCid = 'avatar-'.$site->client_id.'@heptapod';
    $send = function () use ($check, $channel, $transport): Email {
        $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id, 'opened_at' => now()->subMinutes(4),
            'first_failure_at' => now()->subMinutes(5), 'evidence' => ['summary' => 'causes.http', 'summary_params' => ['status' => 503],
                'evidence' => [], 'metrics' => null, 'error' => null, 'run_ended_at' => now()->subMinutes(4)->format('Y-m-d\TH:i:s.uP')]]);

        return mailDesignSend($channel, Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
            'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed]), $transport);
    };

    $plain = $send();
    expect(mailDesignInlineParts($plain))->toBe([])->and($plain->getAttachments())->toBe([])
        ->and($plain->getHtmlBody())->not->toContain('cid:', 'avatar-');

    $settings = app(InstanceSettings::class);
    $settings->mail_design = MailDesign::Branded;
    $settings->save();
    Incident::query()->where('check_id', $check->id)->update(['resolved_at' => now()]);
    $branded = $send();
    expect(mailDesignInlineParts($branded))->toBe(['lockup@heptapod', $avatarCid])
        ->and($branded->getHtmlBody())->toContain('cid:lockup@heptapod', 'cid:'.$avatarCid);
})->with(['regular client' => false, 'No client' => true]);

it('keeps invitation, password reset and verification mail Branded when the instance design is Plain', function (): void {
    $transport = mailDesignTransport();
    expect(app(InstanceSettings::class)->mail_design)->toBe(MailDesign::Plain);
    $user = User::factory()->unverified()->create(['email' => 'account@golden.example', 'locale' => 'en']);

    $user->sendPasswordResetNotification('reset-token');
    $user->sendEmailVerificationNotification();
    Notification::route('mail', 'invitee@golden.example')->notify(new TeamInvitation(Invitation::factory()->create(['email' => 'invitee@golden.example']), 'https://monitor.example.test/invitations/token'));

    $mails = array_map(static fn ($sent) => $sent->getOriginalMessage(), $transport->messages()->all());
    expect(array_map(static fn (Email $mail): string => $mail->getTo()[0]->getAddress(), $mails))
        ->toBe(['account@golden.example', 'account@golden.example', 'invitee@golden.example']);
    foreach ($mails as $mail) {
        expect(mailDesignInlineParts($mail))->toBe(['lockup@heptapod'])
            ->and($mail->getHtmlBody())->toContain('cid:lockup@heptapod', 'class="btn-primary"', '<h1');
    }
});

it('sends a client message in the chosen design, re-renders it as sent and keys the design into idempotency', function (): void {
    $transport = mailDesignTransport();
    $client = Client::factory()->create(['locale' => 'en']);
    $office = mailDesignClientChannel($client, 'office@client.test');
    $check = mailDesignFindings($client, 2);
    $this->actingAs(User::factory()->owner()->create());
    $payload = ['kind' => 'findings', 'check_id' => $check->id, 'channel_ids' => [$office->id], 'attach' => true];

    // The design is a required choice: the sheet always sends it.
    $this->postJson(route('clients.messages.preview', $client), $payload)->assertUnprocessable()->assertJsonValidationErrors('design');
    $preview = $this->postJson(route('clients.messages.preview', $client), [...$payload, 'design' => 'plain'])->assertOk()->json();
    // Plain names an address on the site's own host by its path, keeps another host's full URL, and carries no images.
    expect($preview['html'])->toContain('/products/old-1?ref=nav', 'https://partner.example.test/gone')
        ->not->toContain('https://shop.example.test/products/old-1', 'cid:', 'data:image');
    $branded = $this->postJson(route('clients.messages.preview', $client), [...$payload, 'design' => 'branded'])->assertOk()->json();
    expect($branded['html'])->toContain('https://shop.example.test/products/old-1?ref=nav', 'data:image/png;base64,');

    $sent = $this->postJson(route('clients.messages.store', $client), [...$payload, 'design' => 'plain'], ['Idempotency-Key' => 'design-key'])->assertCreated()->json();
    // The same key with another design is another message, not a replay.
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'design' => 'branded'], ['Idempotency-Key' => 'design-key'])->assertConflict();
    $this->postJson(route('clients.messages.store', $client), [...$payload, 'design' => 'plain'], ['Idempotency-Key' => 'design-key'])->assertOk()->assertJsonPath('replayed', true);
    expect(Event::query()->findOrFail($sent['event_id'])->payload['design'])->toBe('plain');

    $mail = mailDesignDeliverClientMessage($sent['event_id'], $transport)[0];
    expect(mailDesignInlineParts($mail))->toBe([]);
    // Only the Plain HTML shortens own-host addresses: the text part is the same in both designs and keeps full URLs, as does the CSV.
    $other = $this->postJson(route('clients.messages.store', $client), [...$payload, 'design' => 'branded'], ['Idempotency-Key' => 'design-branded'])->assertCreated()->json();
    mailDesignDeliverClientMessage($other['event_id'], $transport);
    $brandedMail = mailDesignLast($transport);
    expect($mail->getTextBody())->toBe($brandedMail->getTextBody())
        ->and($mail->getTextBody())->toContain('https://shop.example.test/products/old-1?ref=nav | 404 | https://shop.example.test/catalog')
        ->and($mail->getHtmlBody())->toContain('>/products/old-1?ref=nav</span>', '>/catalog</span>', '>https://partner.example.test/gone</span>')
        ->not->toContain('https://shop.example.test/products/old-1')
        ->and($brandedMail->getHtmlBody())->toContain('https://shop.example.test/products/old-1?ref=nav');
    $csv = array_first(array_filter($mail->getAttachments(), static fn (DataPart $part): bool => $part->getDisposition() !== 'inline'))->getBody();
    expect($csv)->toContain('https://shop.example.test/products/old-1?ref=nav,404,')->not->toContain(',/products/');
    // "Message as sent" renders the stored design, also after the instance default changes.
    $settings = app(InstanceSettings::class);
    $settings->mail_design = MailDesign::Branded;
    $settings->save();
    $shown = $this->getJson(route('clients.messages.show', [$client, $sent['event_id']]))->assertOk()->json();
    expect($shown['html'])->toBe($preview['html']);
    // "Send again" starts in the design the message was sent in, not the instance default.
    DB::table('deliveries')->where('event_id', $sent['event_id'])->update(['state' => DeliveryState::Failed->value]);
    $rows = collect($this->getJson(route('clients.messages.index', $client))->assertOk()->json('rows'));
    expect($rows->firstWhere('event_id', $sent['event_id'])['resend']['design'] ?? null)->toBe('plain');
});

it('renders a client message stored before the design existed as Branded', function (): void {
    $client = Client::factory()->create(['locale' => 'en']);
    $office = mailDesignClientChannel($client, 'office@client.test');
    $check = mailDesignFindings($client, 1);
    $this->actingAs(User::factory()->owner()->create());
    $sent = $this->postJson(route('clients.messages.store', $client), ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]],
        ['Idempotency-Key' => 'legacy'])->assertCreated()->json();
    $event = Event::query()->findOrFail($sent['event_id']);
    $event->update(['payload' => array_diff_key($event->payload, ['design' => true])]);

    $shown = $this->getJson(route('clients.messages.show', [$client, $event->id]))->assertOk()->json();
    expect($shown['html'])->toContain('data:image/png;base64,', '<h1');
});

it('lets only the Owner change the instance mail design and presets new client messages from it', function (): void {
    $settings = app(InstanceSettings::class);
    $settings->mail_design = MailDesign::Branded;
    $settings->save();
    $admin = User::factory()->create()->assignRole('Admin');
    $owner = User::factory()->owner()->create();

    $this->actingAs($admin)->patchJson(route('settings.instance.update'), ['name' => 'Golden Monitor', 'mail_design' => 'plain'])->assertForbidden();
    $this->actingAs($owner)->patchJson(route('settings.instance.update'), ['name' => 'Golden Monitor', 'mail_design' => 'fancy'])
        ->assertUnprocessable()->assertJsonValidationErrors('mail_design');
    expect(app(InstanceSettings::class)->mail_design)->toBe(MailDesign::Branded);

    $this->patchJson(route('settings.instance.update'), ['name' => 'Golden Monitor', 'mail_design' => 'plain'])->assertOk()->assertJsonPath('mail_design', 'plain');
    expect(app(InstanceSettings::class)->mail_design)->toBe(MailDesign::Plain);
    // A save that names only the instance keeps the design.
    $this->patchJson(route('settings.instance.update'), ['name' => 'Renamed'])->assertOk()->assertJsonPath('mail_design', 'plain');
    $this->getJson(route('settings.instance.data'))->assertOk()->assertJsonPath('mail_design', 'plain')->assertJsonPath('name', 'Renamed');
    $this->get('/settings/instance')->assertOk()->assertInertia(fn (Assert $page): Assert => $page->where('instancePage.mail_design', 'plain'));

    // The pages that open Send to client preset a new message from it.
    $check = mailDesignFindings(Client::factory()->create(), 1);
    $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id]);
    $this->get(route('sites.checks.show', ['site' => $check->site_id, 'check' => $check->id]))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('mail_design', 'plain'));
    $this->get(route('incidents.show', ['incident' => $incident->id]))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('mail_design', 'plain'));
});

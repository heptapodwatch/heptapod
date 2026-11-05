<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\BuildAlertMailContext;
use Heptapod\Actions\DeleteSite;
use Heptapod\Actions\ResolveInactiveCheckIncidents;
use Heptapod\Addons\Channels\CommunityChannel;
use Heptapod\Addons\Channels\MailChannel;
use Heptapod\Addons\Crawl\CrawlEvidence;
use Heptapod\Addons\Crawl\MixedContentFinding;
use Heptapod\Addons\Cron\CronMetrics;
use Heptapod\Addons\Dns\DnsMetrics;
use Heptapod\Checks\CauseSummary;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\ChannelType;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\Schema\ValidatedConfig;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeSmtpTransport;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel as FrameworkMailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Monolog\Handler\TestHandler;
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
    $settings->save();
    Http::preventStrayRequests();
    Http::fake([]);
});

function channelOutcomeMailChannel(string $locale): NotificationDestination
{
    return NotificationDestination::factory()->create(['label' => 'Golden inbox', 'channel' => 'mail', 'locale' => $locale,
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'ops@golden.example'])]);
}

function channelOutcomeSite(string $path): Site
{
    $client = Client::factory()->create(['name' => 'Golden client', 'timezone' => 'Europe/Berlin', 'accent_color' => '#336699']);

    return Site::factory()->for($client)->create(['friendly_name' => 'Golden shop', 'url' => 'https://shop.golden.example/'.$path]);
}

/** @return array<string, Closure(NotificationDestination): Event> */
function channelOutcomeMailEvents(): array
{
    return [
        'uptime opened' => static function (): Event {
            $check = Check::factory()->for(channelOutcomeSite('opened'))->create(['type' => CheckKey::Uptime]);
            $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id, 'opened_at' => now()->subMinutes(4),
                'first_failure_at' => now()->subMinutes(5), 'evidence' => ['summary' => 'causes.http', 'summary_params' => ['status' => 503],
                    'evidence' => [], 'metrics' => null, 'error' => null, 'run_ended_at' => now()->subMinutes(4)->format('Y-m-d\TH:i:s.uP')]]);

            return Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
                'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed]);
        },
        'maintenance catch-up' => static function (): Event {
            $check = Check::factory()->for(channelOutcomeSite('catch-up'))->create(['type' => CheckKey::CertificateHealth]);
            $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id, 'opened_at' => now()->subHour()]);
            $catchUp = Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
                'type' => 'maintenance.catch_up', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
                'payload' => ['uptime_episode' => false, 'count' => 2, 'check_keys' => ['certificate_health', 'uptime'], 'since' => '2026-10-30T08:15:00+00:00']]);
            DB::table('maintenance_catch_up_incidents')->insert(['event_id' => $catchUp->id, 'incident_id' => $incident->id]);

            return $catchUp;
        },
        'digest' => static function (NotificationDestination $channel): Event {
            $sourceIds = [];
            foreach (['digest-a', 'digest-b'] as $path) {
                $warning = Event::factory()->create(['check_id' => Check::factory()->for(channelOutcomeSite($path))->create(['type' => CheckKey::Performance])->id,
                    'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
                $sourceIds[] = Delivery::query()->create(['event_id' => $warning->id, 'destination_id' => $channel->id, 'digest' => true,
                    'next_attempt_at' => now(), 'suppression_evaluated_at' => now()])->id;
            }

            return Event::factory()->create(['check_id' => null, 'site_id' => null, 'type' => 'notification.digest', 'from_result' => null, 'to_result' => null,
                'payload' => ['count' => 2, 'sites' => 'Golden shop, Golden shop', 'remaining_sites' => 0, 'delivery_ids' => $sourceIds]]);
        },
    ];
}

/** Message-ID, Date and multipart boundaries are random per send; ids come from shared sequences. */
function channelOutcomeMailGolden(Email $mail): string
{
    $headers = [];
    foreach (['From', 'To', 'Cc', 'Bcc', 'Reply-To', 'Subject'] as $name) {
        $header = $mail->getHeaders()->get($name);
        if ($header instanceof HeaderInterface) {
            $headers[] = $name.': '.$header->getBodyAsString();
        }
    }
    $parts = array_map(static fn (DataPart $part): string => $part->getMediaType().'/'.$part->getMediaSubtype().' '.($part->getFilename() ?? '').' '.strlen($part->getBody()),
        $mail->getAttachments());
    $text = implode("\n", $headers)."\n-- ATTACHMENTS --\n".implode("\n", $parts)."\n-- PLAIN --\n".$mail->getTextBody()."\n-- HTML --\n".$mail->getHtmlBody();

    return preg_replace(['#/(incidents|sites|checks)/[1-9][0-9]*#', '/(?<=[ ]|\\/)#[1-9][0-9]*\b/', '/avatar-[0-9]+/'], ['/$1/{id}', '#{id}', 'avatar-{id}'], $text)
        ?? throw new LogicException('Could not normalise the mail golden.');
}

it('renders alert, catch-up, digest and test mail unchanged through the real send path', function (string $locale): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel($locale);
    $golden = [];
    foreach (channelOutcomeMailEvents() as $name => $make) {
        $event = $make($channel);
        $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
        $before = $transport->messages()->count();
        app()->call(new SendNotification($delivery->id)->handle(...));
        expect($transport->messages()->count())->toBe($before + 1, $name.' sends one mail');
        expect($delivery->refresh()->state)->toBe(DeliveryState::Sent, $name);
        $mail = $transport->messages()->last()->getOriginalMessage();
        expect($mail)->toBeInstanceOf(Email::class);
        $golden[$name] = channelOutcomeMailGolden($mail);
    }
    $owner = User::factory()->owner()->create(['name' => 'Golden Owner']);
    $this->actingAs($owner)->postJson(route('notification-channels.test', $channel))->assertOk()->assertJsonPath('status', 'sent');
    $golden['test send'] = channelOutcomeMailGolden($transport->messages()->last()->getOriginalMessage());

    expect(implode("\n=====\n", array_map(static fn (string $name, string $text): string => $name."\n".$text, array_keys($golden), $golden)))->toMatchSnapshot();
})->with(['en', 'de']);

/** @param array<string, mixed> $values */
function channelOutcomeChannel(string $channel, array $values, string $locale = 'en'): NotificationDestination
{
    return NotificationDestination::factory()->create(['label' => 'Outcome '.$channel, 'channel' => $channel, 'locale' => $locale,
        'config' => app(ChannelRegistry::class)->get($channel)->configSchema()->validateConfig($values)]);
}

/** One real warning delivery on the channel, sent through the delivery job. */
function channelOutcomeSend(NotificationDestination $channel, string $path = 'outcome', array $delivery = []): Delivery
{
    $event = Event::factory()->create(['check_id' => Check::factory()->for(channelOutcomeSite($path))->create(['type' => CheckKey::Performance])->id,
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
    $row = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id,
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now(), ...$delivery]);
    app()->call(new SendNotification($row->id)->handle(...));

    return $row->refresh();
}

it('records accepted, logged, rejected and failed mail outcomes with retry only for failures', function (string $case, string $state, ?string $outcome, bool $suppressed, ?string $diagnosis): void {
    match ($case) {
        'accepted' => Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport),
        'logged' => config(['mail.default' => 'array']),
        'rejected' => Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('RCPT TO:<ops@golden.example>', 550)),
        'failed' => Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 451)),
    };
    if ($case === 'logged') {
        Mail::purge();
    }
    $channel = channelOutcomeMailChannel('en');
    $delivery = channelOutcomeSend($channel);
    expect($delivery->state->value)->toBe($state)
        ->and($delivery->suppressed)->toBe($suppressed)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_diagnosis)->toBe($diagnosis)
        ->and($channel->refresh()->last_outcome)->toBe($outcome);
    if ($case === 'accepted') {
        // The real transport's Message-ID is the provider id, as before the move to the notification driver.
        expect($delivery->provider_message_id)->toBe($transport->messages()->sole()->getMessageId())->not->toBeNull();
    }
    if ($state === 'failed' && ! $suppressed) {
        expect($delivery->next_attempt_at->toIso8601String())->toBe(now()->addMinute()->toIso8601String());
    }
})->with([
    'accepted' => ['accepted', 'sent', 'accepted', false, null],
    'logged' => ['logged', 'logged', 'logged', false, null],
    'rejected' => ['rejected', 'failed', 'rejected', true, 'SMTP 550 RCPT — SMTP response rejected'],
    'transport failure retries' => ['failed', 'failed', 'failed', false, 'SMTP 451 config — SMTP response rejected'],
]);

it('fails a mail send without a route loudly and never reaches the transport', function (): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $user = User::factory()->member()->create();
    $channel = NotificationDestination::factory()->create(['recipient_kind' => 'user', 'user_id' => $user->id, 'account_mail' => true,
        'locale' => null, 'label' => 'Account', 'config' => new ValidatedConfig([], [])]);
    $user->forceFill(['email' => ''])->saveQuietly();
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    $delivery = channelOutcomeSend($channel);
    expect($delivery->state)->toBe(DeliveryState::Failed)->and($delivery->last_reason)->toBe('notifications.no_route')
        ->and($transport->messages())->toHaveCount(0)
        ->and(collect($handler->getRecords())->contains(fn ($record): bool => $record->message === 'Notification mail has no route.'
            && ($record->context['exception'] ?? null) instanceof LogicException))->toBeTrue();
});

it('refuses a mail notification addressed to anyone but its recipient route', function (array $extra): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel('en');
    $notification = new class(...$extra) extends Notification
    {
        /** @param list<string> $cc
         * @param  list<string>  $envelopeCc
         */
        public function __construct(private readonly string $to, private readonly array $cc = [], private readonly array $envelopeCc = []) {}

        public function toMail(ChannelRecipient $notifiable): Mailable
        {
            // Recipients declared in envelope() join only when Laravel prepares the Mailable for delivery.
            $envelope = new Envelope(cc: $this->envelopeCc);

            return new class($envelope) extends Mailable
            {
                public function __construct(private readonly Envelope $declared) {}

                public function envelope(): Envelope
                {
                    return $this->declared;
                }
            }->to($this->to)->cc($this->cc)->subject('Guard')->html('guard');
        }
    };
    expect(fn () => app(ChannelRegistry::class)->send('mail', ChannelRecipient::forChannel($channel->destinationConfig()), $notification))
        ->toThrow(LogicException::class, 'addressed to exactly its recipient route');
    expect($transport->messages())->toHaveCount(0);
})->with([
    'another address' => [['other@golden.example']],
    'an extra copy' => [['ops@golden.example', ['audit@golden.example']]],
    'an extra copy declared in the envelope' => [['ops@golden.example', [], ['audit@golden.example']]],
]);

it('maps own transport provider replies to exact outcome classes', function (string $channel, array $values, string $url, mixed $reply, int $status, string $state, ?string $diagnosis): void {
    Http::fake([$url => Http::response($reply, $status)]);
    $delivery = channelOutcomeSend(channelOutcomeChannel($channel, $values));
    expect($delivery->state->value)->toBe($state)->and($delivery->last_diagnosis)->toBe($diagnosis);
    Http::assertSentCount(1);
})->with([
    'slack accepted' => ['slack', ['channel' => 'C123', 'token' => 'xoxb-private-token'], 'https://slack.com/api/chat.postMessage', ['ok' => true, 'ts' => '1.2'], 200, 'sent', null],
    'slack ok:false on HTTP 200' => ['slack', ['channel' => 'C123', 'token' => 'xoxb-private-token'], 'https://slack.com/api/chat.postMessage', ['ok' => false, 'error' => 'channel_not_found'], 200, 'failed', 'HTTP 200 — channel_not_found'],
    'webhook rejected' => ['webhook', ['url' => 'https://receiver.example.test/hook', 'secret' => 'outcome-secret'], 'https://receiver.example.test/hook', '', 410, 'failed', 'HTTP 410 — RequestException'],
    'teams non-ack body' => ['microsoftTeams', ['url' => 'https://teams.example.test/hook'], 'https://teams.example.test/hook', 'Webhook disabled', 200, 'failed', 'HTTP 200'],
]);

it('keeps Slack on the guarded HTTP seam: no redirects and bounded timeouts', function (): void {
    $sent = [];
    Http::fake(function (HttpRequest $request, array $options) use (&$sent) {
        $sent[] = ['url' => $request->url(), 'allow_redirects' => $options['allow_redirects'] ?? null,
            'timeout' => $options['timeout'] ?? null, 'connect_timeout' => $options['connect_timeout'] ?? null];

        return Http::response('', 302, ['Location' => 'https://redirect.example.test/']);
    });
    $delivery = channelOutcomeSend(channelOutcomeChannel('slack', ['channel' => 'C123', 'token' => 'xoxb-private-token']));
    expect($delivery->state)->toBe(DeliveryState::Failed)->and($delivery->last_diagnosis)->toBe('HTTP 302');
    expect($sent)->toBe([['url' => 'https://slack.com/api/chat.postMessage', 'allow_redirects' => false, 'timeout' => 20, 'connect_timeout' => 5]]);
});

it('maps a community channel return to sent and any exception to a secret-free failure', function (bool $throws, string $state, ?string $diagnosis): void {
    $vendor = new class($throws)
    {
        /** @var list<array{route: mixed, title: string}> */
        public array $sent = [];

        public function __construct(private readonly bool $throws) {}

        public function send(object $notifiable, AlertNotification $notification): mixed
        {
            if ($this->throws) {
                throw new RuntimeException('Provider refused token community-private-token');
            }
            $this->sent[] = ['route' => $notifiable->routeNotificationFor('community'), 'title' => $notification->message->titleKey];

            return ['provider' => 'any shape'];
        }
    };
    $community = new CommunityChannel('community', 'notifications.channel.webhook', 'notifications.channel.webhook.description', 'bell', new Schema(['room' => new Field(FieldType::String, ['required'])]), $vendor, 'room');
    app()->instance('community-channel', $community);
    app()->tag(['community-channel'], ChannelType::class);
    app()->forgetInstance(ChannelManager::class);
    $delivery = channelOutcomeSend(channelOutcomeChannel('community', ['room' => 'ops-room']));
    expect($delivery->state->value)->toBe($state)->and($delivery->last_diagnosis)->toBe($diagnosis)
        ->and((string) $delivery->last_diagnosis)->not->toContain('community-private-token');
    expect($vendor->sent)->toBe($throws ? [] : [['route' => 'ops-room', 'title' => 'notifications.warning_title']]);
})->with([
    'returns' => [false, 'sent', null],
    'throws' => [true, 'failed', 'RuntimeException'],
]);

it('routes a manual delivery only from its recipient snapshot, also after the channel was edited', function (): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel('en');
    $channel->update(['enabled' => false, 'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'edited@golden.example'])]);
    $delivery = channelOutcomeSend($channel, delivery: ['origin' => 'manual', 'actor_name' => 'Golden Owner',
        'recipient_snapshot' => ['address' => 'approved@golden.example', 'name' => 'Approved contact', 'locale' => 'de']]);
    expect($delivery->state)->toBe(DeliveryState::Sent);
    $mail = $transport->messages()->sole()->getOriginalMessage();
    expect(array_map(static fn ($address): string => $address->getAddress(), $mail->getTo()))->toBe(['approved@golden.example'])
        ->and($mail->getSubject())->toContain('Warnung');
});

it('renders each recipient in its own locale and restores the process locale', function (): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    app()->setLocale('en');
    $user = User::factory()->member()->create(['locale' => 'de', 'email' => 'person@golden.example']);
    $account = NotificationDestination::factory()->create(['recipient_kind' => 'user', 'user_id' => $user->id, 'account_mail' => true,
        'locale' => null, 'label' => 'Account', 'config' => new ValidatedConfig([], [])]);
    $team = channelOutcomeMailChannel('en');
    channelOutcomeSend($account, 'locale-de');
    channelOutcomeSend($team, 'locale-en');
    [$de, $en] = $transport->messages()->map(static fn ($sent) => $sent->getOriginalMessage())->all();
    expect($de->getTo()[0]->getAddress())->toBe('person@golden.example')->and($de->getSubject())->toContain('Warnung')
        ->and($en->getTo()[0]->getAddress())->toBe('ops@golden.example')->and($en->getSubject())->toContain('Warning')
        ->and(app()->getLocale())->toBe('en');
});

it('links "Change notifications" to the settings page of the channel owner', function (): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $user = User::factory()->member()->create(['email' => 'person@golden.example']);
    $account = NotificationDestination::factory()->create(['recipient_kind' => 'user', 'user_id' => $user->id, 'account_mail' => true,
        'locale' => null, 'label' => 'Account', 'config' => new ValidatedConfig([], [])]);
    $site = channelOutcomeSite('link');
    $client = $site->client;
    $office = NotificationDestination::factory()->create(['recipient_kind' => 'client', 'client_id' => $client->id, 'locale' => null, 'label' => 'Office',
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'office@golden.example'])]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Performance]);
    foreach ([$account, $office, channelOutcomeMailChannel('en')] as $channel) {
        $event = Event::factory()->create(['check_id' => $check->id,
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
        $row = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
        app()->call(new SendNotification($row->id)->handle(...));
    }

    expect($transport->messages()->map(static fn ($sent): string => preg_match('#Change notifications: (\S+)#', $sent->getOriginalMessage()->getTextBody(), $link) === 1 ? $link[1] : '')->all())
        ->toBe(['http://monitor.example.test/settings/notifications', 'http://monitor.example.test/clients/'.$client->id.'/notifications',
            'http://monitor.example.test/settings/notifications/team']);
});

it('leaves account mail on the framework mail driver and registers no mail veto listener', function (): void {
    expect(app(ChannelManager::class)->driver('mail'))->toBeInstanceOf(FrameworkMailChannel::class)
        ->and(app(ChannelManager::class)->driver('heptapod.mail'))->toBeInstanceOf(MailChannel::class)
        ->and(Events::hasListeners(MessageSending::class))->toBeFalse();
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $user = User::factory()->create(['email' => 'reset@golden.example']);
    $user->sendPasswordResetNotification('reset-token');
    expect($transport->messages()->sole()->getOriginalMessage()->getTo()[0]->getAddress())->toBe('reset@golden.example');
});

/** Uninstalls the sitemap add-on in this process: no registry entry and none of the translations it registered. */
function channelOutcomeWithoutSitemapAddon(): void
{
    $installed = app(CheckRegistry::class);
    $without = new CheckRegistry(app());
    foreach ($installed->keys() as $key) {
        if ($key !== CheckKey::Sitemap) {
            $without->register($installed->get($key)::class);
        }
    }
    app()->instance(CheckRegistry::class, $without);
    foreach ([NotificationEventTypes::class, NotificationMessage::class, BuildAlertMailContext::class, CheckRunPresentations::class, CauseSummary::class] as $resolved) {
        app()->forgetInstance($resolved);
    }
    $sitemapKeys = [];
    foreach (['en', 'de'] as $locale) {
        $lines = json_decode((string) file_get_contents(base_path('addons/sitemap/lang/'.$locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
        $sitemapKeys[$locale] = array_keys($lines);
    }
    $translator = app('translator');
    foreach (['en', 'de'] as $locale) {
        $translator->load('*', '*', $locale);
    }
    $loaded = (fn (): array => $this->loaded)->call($translator);
    foreach (['en', 'de'] as $locale) {
        $loaded['*']['*'][$locale] = array_diff_key($loaded['*']['*'][$locale], array_flip($sitemapKeys[$locale]));
    }
    $translator->setLoaded($loaded);
}

it('sends and previews retained add-on alerts after their add-on was uninstalled', function (string $locale): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel($locale);
    $check = Check::factory()->for(channelOutcomeSite('sitemap'))->create(['type' => CheckKey::Sitemap]);
    $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id, 'opened_at' => now()->subHour(),
        'evidence' => ['summary' => 'sitemap.failed', 'summary_params' => [], 'evidence' => [], 'metrics' => null, 'error' => null]]);
    // Both alerts were planned while the add-on was installed and carry add-on-owned summaries.
    $changed = Event::factory()->create(['check_id' => $check->id, 'incident_id' => $incident->id, 'type' => 'sitemap.issues_changed',
        'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Failed,
        'payload' => ['result' => 'failed', 'summary' => 'sitemap.failed', 'summary_params' => [], 'new' => [], 'resolved' => []]]);
    $opened = Event::factory()->create(['check_id' => $check->id, 'incident_id' => $incident->id, 'type' => 'incident.opened', 'cause' => 'failed',
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed, 'payload' => ['summary' => 'sitemap.failed', 'summary_params' => []]]);
    $pending = Delivery::query()->create(['event_id' => $changed->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    $accepted = Delivery::query()->create(['event_id' => $opened->id, 'destination_id' => $channel->id, 'state' => DeliveryState::Sent, 'sent_at' => now(),
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    expect($pending->category)->toBe(NotificationCategory::Changes)->and($accepted->category)->toBe(NotificationCategory::Failure);

    channelOutcomeWithoutSitemapAddon();
    expect(NotificationCategory::of($changed->refresh()))->toBeNull()->and(Lang::has('sitemap.failed', $locale))->toBeFalse();

    $unavailable = $locale === 'en' ? 'The add-on for this check is not installed' : 'Das Add-on für diese Prüfung ist nicht installiert';
    app()->call(new SendNotification($pending->id)->handle(...));
    expect($pending->refresh()->state)->toBe(DeliveryState::Sent);
    $mail = $transport->messages()->sole()->getOriginalMessage();
    // The retained result decides the state; the retained category still names what the channel receives.
    expect($mail->getSubject())->toBe($locale === 'en' ? '[Failed] Golden shop — Sitemap' : '[Fehlgeschlagen] Golden shop — Sitemap')
        ->and($mail->getTextBody())->toContain($unavailable, $locale === 'en' ? 'gets changes' : 'Änderungen erhält')->not->toContain('sitemap.failed');

    // The incident page preview of the accepted opening renders through the same retained mail.
    $owner = User::factory()->owner()->create();
    $preview = $this->actingAs($owner)->getJson(route('incidents.alert-preview', ['incident' => $incident->id, 'event' => $opened->id]))->assertOk();
    expect($preview->json('subject'))->toBe($locale === 'en' ? '[Failed] Golden shop — Sitemap' : '[Fehlgeschlagen] Golden shop — Sitemap')
        ->and(html_entity_decode($preview->json('html'), ENT_QUOTES))->toContain($unavailable, $locale === 'en' ? 'gets failures' : 'fehlgeschlagene Prüfungen erhält')
        ->not->toContain('sitemap.failed');
})->with(['en', 'de']);

it('marks only the event\'s new mixed content findings as new in the sent mail', function (string $locale): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel($locale);
    $site = channelOutcomeSite('mixed');
    $site->update(['url' => 'https://shop.golden.example/']);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::MixedContent]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'mixed_content.failed', 'started_at' => now()->subMinute(), 'ended_at' => now(),
        'metrics' => ['total' => 5, 'internal' => 5, 'external' => 0, 'broken_internal' => 0, 'broken_external' => 0, 'mixed_content' => 5],
        'evidence' => new CrawlEvidence([], false, 0, true, false, 100, 1, 5, 5)->toArray()]);
    $snapshot = DB::table('crawl_snapshots')->insertGetId(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000002', 'run_ended_at' => $run->ended_at, 'collected_at' => $run->ended_at,
        'settings' => '{}', 'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => true, 'evaluated_finding_ids' => '[]', 'site_url' => $site->url, 'target_fingerprint' => str_repeat('a', 64)]);
    $findings = [];
    foreach (['existing-1', 'existing-2', 'existing-3', 'existing-4', 'added'] as $name) {
        $finding = new MixedContentFinding('img', 'http://cdn.golden.example/'.$name.'.png', 'https://shop.golden.example/', 'https://shop.golden.example/');
        DB::table('mixed_content_findings')->insert(['snapshot_id' => $snapshot, 'element_name' => $finding->element_name,
            'mixed_content_url' => $finding->mixed_content_url, 'found_on_url' => $finding->found_on_url, 'source_url' => $finding->source_url]);
        $findings[$name] = $finding;
    }
    $event = Event::factory()->create(['check_id' => $check->id, 'type' => 'mixed_content.new_finding', 'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Failed,
        'occurred_at' => $run->ended_at, 'payload' => ['result' => 'failed', 'count' => 1, 'finding_ids' => [$findings['added']->identity()], 'truncated' => false,
            'run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\TH:i:s.uP')]]);
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    app()->call(new SendNotification($delivery->id)->handle(...));

    $text = $transport->messages()->sole()->getOriginalMessage()->getTextBody();
    $new = $locale === 'en' ? 'new' : 'neu';
    // All five findings are listed under the general heading; only the event's one identity reads as new.
    expect($text)->toContain($locale === 'en' ? '1 insecure resource is new since the last crawl.' : '1 unsichere Ressource ist neu seit dem letzten Crawl.')
        ->toContain($locale === 'en' ? 'Insecure resources' : 'Unsichere Ressourcen')
        ->not->toContain('New since the last crawl', 'Neu seit dem letzten Crawl');
    expect(substr_count($text, ' ('.$new.')'))->toBe(1)
        ->and($text)->toContain('http://cdn.golden.example/added.png ('.$new.') | https://shop.golden.example/');
    foreach (['existing-1', 'existing-2', 'existing-3', 'existing-4'] as $name) {
        expect($text)->toContain('http://cdn.golden.example/'.$name.'.png | https://shop.golden.example/'."\n");
    }
    // The marker sits in the URL cell and never wraps; the table keeps its three columns.
    $html = $transport->messages()->sole()->getOriginalMessage()->getHtmlBody();
    // The kind label does not break mid-word either.
    expect(preg_match_all('/white-space:nowrap;">'.($locale === 'en' ? 'Image' : 'Bild').'<\/span>/', $html))->toBe(5);
    expect(substr_count($html, 'white-space:nowrap;border:1px solid'))->toBe(1)
        ->and($html)->toMatch('/added\.png<\/span>&nbsp;<span[^>]*white-space:nowrap[^>]*>'.$new.'<\/span>/');
    preg_match('/<tr>((?:\s*<td class="c-fg3"[^>]*>[^<]*<\/td>)+)\s*<\/tr>/', $html, $head);
    expect(substr_count($head[1] ?? '', '<td'))->toBe(3);
})->with(['en', 'de']);

it('keeps an administrative closure Closed and historical when its site is deleted before the send', function (string $locale): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel($locale);
    $site = channelOutcomeSite('closed');
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $incident = Incident::factory()->for($check)->create(['site_id' => $site->id, 'opened_at' => now()->subHour(), 'resolved_at' => now()]);
    // A closure only goes to channels whose opening alert was sent.
    $opening = Event::factory()->create(['check_id' => $check->id, 'incident_id' => $incident->id, 'type' => 'incident.opened', 'cause' => 'failed',
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed, 'occurred_at' => now()->subHour()]);
    Delivery::query()->create(['event_id' => $opening->id, 'destination_id' => $channel->id, 'state' => DeliveryState::Sent, 'sent_at' => now()->subHour(),
        'next_attempt_at' => now()->subHour(), 'suppression_evaluated_at' => now()->subHour()]);
    // Pausing the site closed the incident without a recovery run (ResolveInactiveCheckIncidents).
    $closed = Event::factory()->create(['check_id' => $check->id, 'incident_id' => $incident->id, 'type' => 'incident.resolved', 'cause' => 'failed',
        'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Failed,
        'payload' => ['summary' => 'incidents.resolution.site_paused', 'summary_params' => ['resolution_key' => 'incidents.resolution.site_paused'], 'resolution_reason' => 'site_paused']]);
    $delivery = Delivery::query()->create(['event_id' => $closed->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    // The site is deleted right after the claim commits, before the mail is built.
    $deleted = false;
    Delivery::updated(static function (Delivery $claimed) use ($delivery, $site, &$deleted): void {
        if ($claimed->id === $delivery->id && $claimed->state === DeliveryState::Sending && ! $deleted) {
            $deleted = true;
            DB::afterCommit(static fn () => app(DeleteSite::class)(Site::query()->findOrFail($site->id)));
        }
    });

    app()->call(new SendNotification($delivery->id)->handle(...));

    expect($deleted)->toBeTrue()->and($closed->refresh()->site_id)->toBeNull()->and($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    $mail = $transport->messages()->sole()->getOriginalMessage();
    $text = $mail->getTextBody();
    expect($mail->getSubject())->toBe($locale === 'en' ? '[Closed] Deleted site: Golden shop — Uptime' : '[Geschlossen] Gelöschte Website: Golden shop — Erreichbarkeit')
        ->and($text)->toContain(
            $locale === 'en' ? 'Monitoring stopped, so the incident was closed. No check confirmed that the problem is fixed.' : 'Keine Prüfung hat bestätigt, dass das Problem behoben ist.',
            $locale === 'en' ? 'This site has been deleted' : 'Diese Website wurde gelöscht',
            $locale === 'en' ? 'Site paused' : 'Website pausiert')
        ->not->toContain('[Recovered]', '[Entwarnung]', 'OK again', 'http://monitor.example.test/incidents', 'http://monitor.example.test/sites/');
    expect($mail->getHtmlBody())->not->toContain('href="http://monitor.example.test/incidents', 'href="http://monitor.example.test/sites/');
})->with(['en', 'de']);

it('reports retained observations and a deleted site\'s catch-up in the past tense', function (string $locale): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel($locale);
    // A warning whose check was removed after planning.
    $check = Check::factory()->for(channelOutcomeSite('removed'))->create(['type' => CheckKey::Performance]);
    $warning = Event::factory()->create(['check_id' => $check->id, 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning,
        'payload' => ['summary' => 'performance.slow', 'summary_params' => ['average' => 2350]]]);
    $removed = Delivery::query()->create(['event_id' => $warning->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    // A catch-up whose site is deleted after the claim.
    $site = channelOutcomeSite('gone');
    $failing = Check::factory()->for($site)->create(['type' => CheckKey::CertificateHealth]);
    $incident = Incident::factory()->for($failing)->create(['site_id' => $site->id, 'opened_at' => now()->subHour()]);
    $catchUp = Event::factory()->create(['site_id' => $site->id, 'check_id' => $failing->id, 'incident_id' => $incident->id,
        'type' => 'maintenance.catch_up', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
        'payload' => ['uptime_episode' => false, 'count' => 1, 'check_keys' => ['certificate_health'], 'since' => now()->subHour()->toIso8601String()]]);
    DB::table('maintenance_catch_up_incidents')->insert(['event_id' => $catchUp->id, 'incident_id' => $incident->id]);
    $gone = Delivery::query()->create(['event_id' => $catchUp->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    // Each removal lands right after its delivery's claim commits, before the mail is built.
    $removals = [$removed->id => static fn () => $check->delete(), $gone->id => static fn () => app(DeleteSite::class)(Site::query()->findOrFail($site->id))];
    Delivery::updated(static function (Delivery $claimed) use (&$removals): void {
        if ($claimed->state === DeliveryState::Sending && isset($removals[$claimed->id])) {
            DB::afterCommit($removals[$claimed->id]);
            unset($removals[$claimed->id]);
        }
    });

    app()->call(new SendNotification($removed->id)->handle(...));
    app()->call(new SendNotification($gone->id)->handle(...));

    expect($removals)->toBe([]);
    [$warningMail, $catchUpMail] = $transport->messages()->map(static fn ($sent) => $sent->getOriginalMessage())->all();
    expect($warningMail->getTextBody())->toContain($locale === 'en' ? 'Performance reported a warning on Golden shop.' : 'Leistung hat eine Warnung auf Golden shop gemeldet.')
        ->not->toContain('reports a warning', 'meldet eine Warnung');
    expect($catchUpMail->getSubject())->toBe($locale === 'en' ? '[After maintenance] Deleted site: Golden shop was still failing' : '[Nach Wartung] Gelöschte Website: Golden shop meldete weiterhin Fehler');
})->with(['en', 'de']);

it('names same-named digest sites as the settings UI does, after URL normalisation', function (): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel('en');
    $client = Client::factory()->create(['name' => 'Kestrel', 'timezone' => 'Europe/Berlin']);
    // A default port is no distinction and an encoded path stays as written.
    $urls = [10 => 'https://example.test/shop', 11 => 'https://example.test:443/shop', 12 => 'https://example.test/a%7Eb', 13 => 'https://example.test/a~b', 14 => 'https://example.test:8443/a~b'];
    $sourceIds = [];
    foreach ($urls as $url) {
        $site = Site::factory()->for($client)->create(['friendly_name' => 'Website', 'url' => $url]);
        $event = Event::factory()->create(['check_id' => Check::factory()->for($site)->create(['type' => CheckKey::Performance])->id,
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
        $sourceIds[$site->id] = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'digest' => true,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()])->id;
    }
    foreach ([['A', 'https://foo.test'], ['A', 'https://bar.test'], ['A (foo.test)', 'https://literal.test']] as [$name, $url]) {
        $site = Site::factory()->for($client)->create(['friendly_name' => $name, 'url' => $url]);
        $event = Event::factory()->create(['check_id' => Check::factory()->for($site)->create(['type' => CheckKey::Performance])->id,
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
        $sourceIds[$site->id] = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'digest' => true,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()])->id;
    }
    $digest = Event::factory()->create(['check_id' => null, 'site_id' => null, 'type' => 'notification.digest', 'from_result' => null, 'to_result' => null,
        'payload' => ['count' => count($sourceIds), 'sites' => 'Website', 'remaining_sites' => 0, 'delivery_ids' => array_values($sourceIds)]]);
    $delivery = Delivery::query()->create(['event_id' => $digest->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    app()->call(new SendNotification($delivery->id)->handle(...));

    $siteIds = array_keys($sourceIds);
    $text = $transport->messages()->sole()->getOriginalMessage()->getTextBody();
    expect($text)->toContain('- A (Kestrel · foo.test) · Performance · Warning', '- A (foo.test) (literal.test) · Performance · Warning');
    preg_match_all('/^- (Website \(.+\)) · Performance · Warning$/m', $text, $rows);
    expect($rows[1])->toBe([
        'Website (Kestrel · example.test/a%7Eb)',
        'Website (Kestrel · example.test/a~b)',
        'Website (Kestrel · example.test/shop · #'.$siteIds[0].')',
        'Website (Kestrel · example.test/shop · #'.$siteIds[1].')',
        'Website (Kestrel · example.test:8443/a~b)',
    ]);
});

it('sends a digest whose add-on Changes source lost its add-on after the digest was created', function (string $locale): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel($locale);
    $sourceIds = [];
    $sitemap = Check::factory()->for(channelOutcomeSite('digest-sitemap'))->create(['type' => CheckKey::Sitemap]);
    $changed = Event::factory()->create(['check_id' => $sitemap->id, 'type' => 'sitemap.issues_changed', 'from_result' => CheckResult::Warning, 'to_result' => CheckResult::Warning,
        'payload' => ['result' => 'warning', 'new' => [], 'resolved' => []]]);
    $warning = Event::factory()->create(['check_id' => Check::factory()->for(channelOutcomeSite('digest-perf'))->create(['type' => CheckKey::Performance])->id,
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
    foreach ([$changed, $warning] as $source) {
        $sourceIds[] = Delivery::query()->create(['event_id' => $source->id, 'destination_id' => $channel->id, 'digest' => true,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()])->id;
    }
    expect(Delivery::query()->findOrFail($sourceIds[0])->category)->toBe(NotificationCategory::Changes);
    $digest = Event::factory()->create(['check_id' => null, 'site_id' => null, 'type' => 'notification.digest', 'from_result' => null, 'to_result' => null,
        'payload' => ['count' => 2, 'sites' => 'Golden shop, Golden shop', 'remaining_sites' => 0, 'delivery_ids' => $sourceIds]]);
    $delivery = Delivery::query()->create(['event_id' => $digest->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    channelOutcomeWithoutSitemapAddon();
    expect(NotificationCategory::of($changed->refresh()))->toBeNull();

    app()->call(new SendNotification($delivery->id)->handle(...));

    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    preg_match_all('/^- Golden shop \(.+\) · (.+)$/m', $transport->messages()->sole()->getOriginalMessage()->getTextBody(), $rows);
    // The stored routing category orders and names the row: Warning before Changes.
    expect($rows[1])->toBe($locale === 'en' ? ['Performance · Warning', 'Sitemap · Changes'] : ['Leistung · Warnung', 'Sitemap · Änderungen']);
})->with(['en', 'de']);

it('sends a retained add-on alert whose site was deleted and whose add-on was uninstalled', function (string $locale): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel($locale);
    $site = channelOutcomeSite('sitemap-gone');
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Sitemap]);
    $event = Event::factory()->create(['check_id' => $check->id, 'type' => 'sitemap.issues_changed', 'from_result' => CheckResult::Warning, 'to_result' => CheckResult::Warning,
        'payload' => ['result' => 'warning', 'summary' => 'sitemap.failed', 'summary_params' => [], 'new' => [], 'resolved' => []]]);
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    channelOutcomeWithoutSitemapAddon();
    // The site is deleted right after the claim commits, before the mail is built.
    $deleted = false;
    Delivery::updated(static function (Delivery $claimed) use ($delivery, $site, &$deleted): void {
        if ($claimed->id === $delivery->id && $claimed->state === DeliveryState::Sending && ! $deleted) {
            $deleted = true;
            DB::afterCommit(static fn () => app(DeleteSite::class)(Site::query()->findOrFail($site->id)));
        }
    });

    app()->call(new SendNotification($delivery->id)->handle(...));

    expect($deleted)->toBeTrue()->and($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    $mail = $transport->messages()->sole()->getOriginalMessage();
    expect($mail->getSubject())->toBe($locale === 'en' ? '[Warning] Deleted site: Golden shop — Sitemap' : '[Warnung] Gelöschte Website: Golden shop — Sitemap')
        ->and($mail->getTextBody())->toContain(
            $locale === 'en' ? 'This site has been deleted' : 'Diese Website wurde gelöscht',
            $locale === 'en' ? 'The add-on for this check is not installed' : 'Das Add-on für diese Prüfung ist nicht installiert',
            $locale === 'en' ? 'gets changes' : 'Änderungen erhält')
        ->not->toContain('sitemap.failed', 'http://monitor.example.test/sites/', 'http://monitor.example.test/incidents');
})->with(['en', 'de']);

/** Records one accepted run through the real recorder (incident transitions and outbox events included). */
function channelOutcomeRecord(Check $check, CheckOutcome $outcome): void
{
    $claim = ClaimedRun::start($check, CarbonImmutable::now('UTC')->subSecond());
    expect(app(ResultRecorder::class)->record(new RecordableRun($check->id, $claim->claimToken, $claim->startedAt, CarbonImmutable::now('UTC'), $outcome))->accepted)->toBeTrue();
}

it('tells a cron no-tasks closure, a stopped-monitoring closure and a measured recovery apart', function (string $locale): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel($locale);
    $send = static function (Event $resolved) use ($channel, $transport): Email {
        // A closure is only sent to channels whose opening was sent.
        $opening = Event::query()->where('incident_id', $resolved->incident_id)->where('type', 'incident.opened')->sole();
        Delivery::query()->create(['event_id' => $opening->id, 'destination_id' => $channel->id, 'state' => DeliveryState::Sent, 'sent_at' => now(),
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
        $delivery = Delivery::query()->create(['event_id' => $resolved->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
        app()->call(new SendNotification($delivery->id)->handle(...));
        expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);

        return $transport->messages()->last()->getOriginalMessage();
    };
    $open = static function (Check $check, CheckOutcome $failure): void {
        $check->update(['failure_threshold' => 1]);
        channelOutcomeRecord($check, $failure);
        expect($check->incidents()->whereNull('resolved_at')->count())->toBe(1);
    };

    // Cron: the last task was removed while monitoring runs; the check reports no tasks and closes its incident itself.
    $cron = Check::factory()->for(channelOutcomeSite('cron'))->create(['type' => CheckKey::Cron, 'enabled' => true,
        'config' => app(CheckRegistry::class)->get(CheckKey::Cron)->definition()->configSchema->validateConfig([])]);
    $open($cron, new CheckOutcome(RunResult::Failed, 'cron.tasks_failed', ['failed' => 1, 'total' => 1, 'names' => 'backup'], new CronMetrics(0, 0, 1, 0)));
    channelOutcomeRecord($cron, new CheckOutcome(RunResult::Succeeded, 'cron.no_tasks', ['resolution_key' => 'cron.resolved_no_tasks'], new CronMetrics(0, 0, 0, 0)));
    $noTasks = $send(Event::query()->where('check_id', $cron->id)->where('type', 'incident.resolved')->sole());
    expect($noTasks->getSubject())->toBe($locale === 'en' ? '[Closed] Golden shop — Scheduled tasks' : '[Geschlossen] Golden shop — Geplante Aufgaben')
        ->and($noTasks->getTextBody())->toContain($locale === 'en' ? 'closed because the check has no tasks any more' : 'weil die Prüfung keine Aufgaben mehr hat')
        ->not->toContain('Monitoring stopped', 'Überwachung wurde beendet', 'Überwachung beendet', '[Recovered]', '[Entwarnung]');

    // DNS: pausing the site stops monitoring and closes the incident (ResolveInactiveCheckIncidents records resolution_reason).
    $paused = Check::factory()->for(channelOutcomeSite('paused'))->create(['type' => CheckKey::Dns, 'enabled' => true,
        'config' => app(CheckRegistry::class)->get(CheckKey::Dns)->definition()->configSchema->validateConfig([])]);
    $open($paused, new CheckOutcome(RunResult::Failed, 'dns.issue.records_missing', ['types' => 'AAAA'], new DnsMetrics(1, 1, false)));
    $paused->site->update(['paused_at' => now()]);
    app(ResolveInactiveCheckIncidents::class)($paused->refresh());
    $stopped = Event::query()->where('check_id', $paused->id)->where('type', 'incident.resolved')->sole();
    expect($stopped->payload['resolution_reason'])->toBe('site_paused');
    // The recorder suppresses it for planning; the retained closure itself is what a queued delivery renders.
    $stopped->update(['suppressed' => false, 'suppression_reason' => null]);
    $closed = $send($stopped);
    expect($closed->getSubject())->toBe($locale === 'en' ? '[Closed] Golden shop — DNS' : '[Geschlossen] Golden shop — DNS')
        ->and($closed->getTextBody())->toContain($locale === 'en' ? 'Monitoring stopped, so the incident was closed.' : 'Die Überwachung wurde beendet, daher wurde die Störung geschlossen.',
            $locale === 'en' ? 'Site paused' : 'Website pausiert')
        ->not->toContain('no tasks', 'keine Aufgaben');

    // A measured recovery (an accepted good run) stays a recovery.
    $recovering = Check::factory()->for(channelOutcomeSite('recovering'))->create(['type' => CheckKey::Dns, 'enabled' => true,
        'config' => app(CheckRegistry::class)->get(CheckKey::Dns)->definition()->configSchema->validateConfig([])]);
    $open($recovering, new CheckOutcome(RunResult::Failed, 'dns.issue.records_missing', ['types' => 'AAAA'], new DnsMetrics(1, 1, false)));
    channelOutcomeRecord($recovering, new CheckOutcome(RunResult::Succeeded, 'dns.succeeded', [], new DnsMetrics(1, 0, false)));
    $recovered = $send(Event::query()->where('check_id', $recovering->id)->where('type', 'incident.resolved')->sole());
    expect($recovered->getSubject())->toStartWith($locale === 'en' ? '[Recovered]' : '[Entwarnung]')
        ->and($recovered->getTextBody())->not->toContain('[Closed]', 'Monitoring stopped', 'no tasks');
})->with(['en', 'de']);

it('keeps twenty long same-named digest rows unique and within the body budget', function (string $locale): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $channel = channelOutcomeMailChannel($locale);
    $client = Client::factory()->create(['name' => 'Kestrel', 'timezone' => 'Europe/Berlin']);
    $sourceIds = [];
    // One host, one client, one name; paths differ only after a long shared prefix (sites.url accepts 2048 characters).
    for ($index = 1; $index <= 20; $index++) {
        $site = Site::factory()->for($client)->create(['friendly_name' => 'Website', 'url' => 'https://shop.example.test/'.str_repeat('catalogue/', 180).'section-'.$index]);
        $event = Event::factory()->create(['check_id' => Check::factory()->for($site)->create(['type' => CheckKey::Performance])->id,
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
        $sourceIds[] = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'digest' => true,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()])->id;
    }
    $digest = Event::factory()->create(['check_id' => null, 'site_id' => null, 'type' => 'notification.digest', 'from_result' => null, 'to_result' => null,
        'payload' => ['count' => 20, 'sites' => 'Website', 'remaining_sites' => 0, 'delivery_ids' => $sourceIds]]);
    $delivery = Delivery::query()->create(['event_id' => $digest->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    app()->call(new SendNotification($delivery->id)->handle(...));

    $text = $transport->messages()->sole()->getOriginalMessage()->getTextBody();
    preg_match_all('/^- (Website .+) · '.($locale === 'en' ? 'Performance · Warning' : 'Leistung · Warnung').'$/m', $text, $rows);
    expect($rows[1])->toHaveCount(20)
        ->and(array_unique($rows[1]))->toHaveCount(20)
        ->and(strlen($text))->toBeLessThanOrEqual(16000);
    // Each label keeps its distinguishing end: the section of its path.
    foreach (range(1, 20) as $index) {
        expect(array_filter($rows[1], static fn (string $label): bool => str_ends_with($label, '/section-'.$index.')')))->toHaveCount(1);
    }
})->with(['en', 'de']);

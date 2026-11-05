<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\RecordsReviewRuns;
use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Addons\Crawl\CrawlMetrics;
use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Checks\CheckEnablement;
use Heptapod\Checks\History\DatabaseHistoryReader;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CertificateFetchResult;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\DomainRegistrationResult;
use Heptapod\Contracts\DomainRegistrationSource;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Contracts\LighthouseReport;
use Heptapod\Contracts\PublicSuffixList;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\NotificationSiteMute;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ExamplePublicSuffixList;
use Heptapod\Testing\FakeCertificateFetcher;
use Heptapod\Testing\FakeChromiumRenderer;
use Heptapod\Testing\FakeDnsResolver;
use Heptapod\Testing\FakeDomainRegistrationLookup;
use Heptapod\Testing\FakeOutboundHttp;
use Heptapod\Testing\FakeTargetResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Pdp\Domain;

final class AgencyDemoSeeder extends Seeder
{
    use RecordsReviewRuns;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Agency fixtures are restricted to local and testing environments.');
        }
        if (Site::query()->where('url', 'https://hollisandpike.example')->getQuery()->exists()) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        $http = new FakeOutboundHttp;
        $renderer = new FakeChromiumRenderer;
        $certificates = new FakeCertificateFetcher;
        $domains = new FakeDomainRegistrationLookup;
        $dns = new FakeDnsResolver;
        $targets = new FakeTargetResolver;
        $fakes = [
            OutboundHttp::class => $http, ChromiumRenderer::class => $renderer,
            CertificateFetcher::class => $certificates, DomainRegistrationLookup::class => $domains,
            DnsResolver::class => $dns, TargetResolver::class => $targets,
            PublicSuffixList::class => new ExamplePublicSuffixList,
        ];
        $originals = [];
        foreach ($fakes as $contract => $fake) {
            $originals[$contract] = app($contract);
            app()->instance($contract, $fake);
        }
        // Sitemap pacing must advance the fixture clock, rather than wait forever at a frozen timestamp.
        Sleep::fake(syncWithCarbon: true);
        try {
            DB::transaction(function () use ($now, $http, $renderer, $certificates, $domains, $dns, $targets): void {
                // reserved .example hosts; never fetched
                $portfolio = [
                    ['Hollis & Pike', 'H&P', 'en', 'Europe/London', '#475569', ['Website' => 'hollisandpike.example']],
                    ['Marlowe & Vane', 'M&V', 'en', 'Europe/London', '#9A5944', ['Website' => 'marlowevane.example']],
                    ['Osterberg Haus', 'OH', 'en', 'Europe/Copenhagen', '#54736A', ['Website' => 'osterberghaus.example', 'Shop' => 'shop.osterberghaus.example']],
                    ['Atelier Sorrell', 'AS', 'en', 'Europe/Paris', '#956477', ['Website' => 'ateliersorrell.example']],
                    ['Kestrel Mobility', 'KM', 'de', 'Europe/Berlin', '#436C99', ['Website' => 'kestrelmobility.example', 'App' => 'app.kestrelmobility.example', 'API' => 'api.kestrelmobility.example']],
                    ['The Fenwick Trust', 'FT', 'en', 'Europe/London', '#61784A', ['Website' => 'fenwicktrust.example', 'Donations' => 'donate.fenwicktrust.example']],
                    ['Calloway Hotels', 'CH', 'en', 'Europe/Lisbon', '#AA8448', ['Website' => 'calloway-hotels.example', 'Booking' => 'booking.calloway-hotels.example']],
                    ['Brightwater Gin', 'BG', 'en', 'Europe/London', '#408589', ['Website' => 'brightwatergin.example']],
                    ['Nakamura Kiln', 'NK', 'en', 'Asia/Tokyo', '#876D59', ['Website' => 'nakamura-kiln.example']],
                    ['Ardent Legal', 'AL', 'en', 'Europe/Dublin', '#66668B', ['Website' => 'ardentlegal.example']],
                    ['Halvorsen Sailing', 'HS', 'en', 'Europe/Oslo', '#507F9B', ['Website' => 'halvorsensailing.example']],
                    ['Lumen Film Festival', 'LF', 'en', 'Europe/Amsterdam', '#A06051', ['Website' => 'lumenfilmfestival.example', 'Tickets' => 'tickets.lumenfilmfestival.example']],
                ];
                $reportJson = file_get_contents(base_path('addons/lighthouse/tests/Fixtures/mobile.json'));
                if ($reportJson === false) {
                    throw new \RuntimeException('Agency Lighthouse report fixture is missing.');
                }
                /** @var array<string, mixed> $report */
                $report = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);
                $owner = User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->firstOrFail();
                $owner->update(['name' => 'Alex Hollis', 'locale' => 'en', 'timezone' => 'Europe/London']);
                // Demo members are visuals only: a discarded random password means only the demo owner can sign in, and they
                // get only their account channel with every category off, so nothing is ever addressed to these fictional mailboxes.
                foreach ([['Priya Raman', 'priya', 'Admin'], ['Tom Whitlock', 'tom', 'Member']] as [$name, $mailbox, $role]) {
                    $member = User::factory()->create(['name' => $name, 'email' => $mailbox.'@hollisandpike.example', 'locale' => 'en', 'timezone' => 'Europe/London', 'password' => Hash::make(Str::random(64))]);
                    $member->syncRoles([$role]);
                    $silent = app(EnsureAccountChannel::class)($member);
                    NotificationPreference::writeGrid($silent->id, array_map(static fn (): NotificationMode => NotificationMode::Off, NotificationPreference::defaults(RecipientKind::User)));
                }
                // The owner hears Outage, Failure and Recovery only for three showcase sites; every other site is muted.
                $ownerChannel = app(EnsureAccountChannel::class)($owner);
                NotificationPreference::writeGrid($ownerChannel->id, NotificationPreference::defaults(RecipientKind::User, 'Member'));
                foreach ($portfolio as $index => [$name, $shortName, $locale, $timezone, $color, $hosts]) {
                    $values = ['name' => $name, 'short_name' => $shortName, 'locale' => $locale, 'timezone' => $timezone, 'accent_color' => $color];
                    if ($index === 0) {
                        $client = Client::query()->where('is_internal', true)->firstOrFail();
                        $client->update($values);
                    } else {
                        $client = Client::query()->create([...$values, 'slug' => Str::slug($name)]);
                    }
                    foreach ($hosts as $friendlyName => $host) {
                        // No observer may enqueue a favicon fetch for these reserved fixture hosts.
                        $site = Site::withoutEvents(fn (): Site => Site::query()->create([
                            'client_id' => $client->id, 'friendly_name' => $friendlyName, 'url' => 'https://'.$host,
                            'badge_id' => (string) Str::uuid(), 'icon_attempted_at' => $now, 'created_at' => $now->subDays(30),
                        ]));
                        $this->icon($site, $shortName, $color, $now);
                        $site->attachTags(match ($friendlyName) {
                            'Shop' => ['production', 'shop'],
                            'App', 'API' => ['production'],
                            default => ['production', 'marketing'],
                        }, 'sites');
                        $targets->respondWith($host, ['203.0.113.10']);
                        $this->dnsResponses($dns, $host);
                        $domain = app(PublicSuffixList::class)->rules()->resolve(Domain::fromIDNA2008($host))->registrableDomain()->toString();
                        $domains->respondWith($domain, new DomainRegistrationResult(DomainRegistrationSource::Rdap, [
                            'objectClassName' => 'domain', 'ldhName' => $domain, 'status' => ['active'],
                            'events' => [['eventAction' => 'expiration', 'eventDate' => $now->addDays($host === 'brightwatergin.example' ? 24 : 240)->toIso8601String()]],
                        ], 'https://rdap.example/'));
                        $certificates->respondWith($host, new CertificateFetchResult([$this->certificate($host, $host === 'shop.osterberghaus.example' ? 6 : 180)], $host, 443, true));
                        $checks = [];
                        $keys = [CheckKey::Uptime, CheckKey::Performance, CheckKey::CertificateHealth, CheckKey::Dns, CheckKey::Domain];
                        if ($friendlyName === 'Website') {
                            $keys = [...$keys, CheckKey::BrokenLinks, CheckKey::Sitemap];
                        }
                        if (in_array($host, ['osterberghaus.example', 'ateliersorrell.example', 'calloway-hotels.example', 'brightwatergin.example', 'lumenfilmfestival.example'], true)) {
                            $keys[] = CheckKey::Lighthouse;
                        }
                        if (in_array($host, ['donate.fenwicktrust.example', 'api.kestrelmobility.example'], true)) {
                            $keys[] = CheckKey::Cron;
                        }
                        if ($host === 'app.kestrelmobility.example') {
                            $keys[] = CheckKey::ApplicationHealth;
                        }
                        foreach ($keys as $key) {
                            $checks[$key->value] = $this->check($site, $key, $now);
                        }
                        $tasks = isset($checks['cron']) ? $this->tasks($checks['cron'], $host, $now) : [];
                        // Daily uptime/performance and weekly addon samples span 30 days without minute-by-minute bulk.
                        foreach (range(30, 1) as $day) {
                            $at = $now->subDays($day);
                            $this->httpResponses($http, $host, 0.3 + (($index + $day) % 10) * 0.02);
                            $this->evaluate($checks['uptime'], $at, history: app(DatabaseHistoryReader::class));
                            $this->evaluate($checks['performance'], $at->addSeconds(2), history: app(DatabaseHistoryReader::class));
                            if ($day === 5 + $index || ($host === 'api.kestrelmobility.example' && $day === 1)) {
                                $this->httpResponses($http, $host, 0.42, down: true);
                                $this->evaluate($checks['uptime'], $at->addMinutes(1), history: app(DatabaseHistoryReader::class));
                                $this->httpResponses($http, $host, 0.38);
                                $this->evaluate($checks['uptime'], $at->addMinutes($host === 'api.kestrelmobility.example' && $day === 1 ? 7 : 4 + $index % 4), history: app(DatabaseHistoryReader::class));
                            }
                            if (in_array($day, [30, 21, 14, 7, 1], true)) {
                                foreach ($tasks as $task) {
                                    $this->ping($task, $at->subMinute());
                                }
                                $this->lighthouseResponse($renderer, $site, $report, $at, false);
                                foreach ($checks as $key => $check) {
                                    if (! in_array($key, ['uptime', 'performance'], true)) {
                                        // Crawl deadlines use wall time; historical rows use a recorded healthy snapshot.
                                        $outcome = $check->type === CheckKey::BrokenLinks
                                            ? new CheckOutcome(RunResult::Succeeded, 'broken_links.succeeded', [], new CrawlMetrics(1, 1, 0, 0, 0, 0)) : null;
                                        $this->evaluate($check, $at->addSeconds(4), $outcome, app(DatabaseHistoryReader::class));
                                    }
                                }
                            }
                        }
                        foreach ([23, 20, 17, 14, 11, 8, 5, 2] as $hours) {
                            $at = $now->subHours($hours);
                            $this->httpResponses($http, $host, 0.24 + (($index + $hours) % 14) * 0.03);
                            $this->evaluate($checks['uptime'], $at, history: app(DatabaseHistoryReader::class));
                            $this->evaluate($checks['performance'], $at->addSeconds(2), history: app(DatabaseHistoryReader::class));
                        }
                        $this->httpResponses($http, $host, 0.34 + ($index % 8) * 0.04, broken: $host === 'lumenfilmfestival.example');
                        $this->evaluate($checks['uptime'], $now->subMinutes(30), history: app(DatabaseHistoryReader::class));
                        $this->evaluate($checks['performance'], $now->subMinutes(29), history: app(DatabaseHistoryReader::class));
                        if ($host === 'api.kestrelmobility.example') {
                            $this->httpResponses($http, $host, 0.42, down: true);
                            $this->evaluate($checks['uptime'], $now->subMinutes(25), history: app(DatabaseHistoryReader::class));
                            $this->evaluate($checks['uptime'], $now->subMinute(), history: app(DatabaseHistoryReader::class));
                            $this->httpResponses($http, $host, 0.42);
                        }
                        foreach ($tasks as $task) {
                            if ($task->name !== 'Nightly donation export') {
                                $this->ping($task, $now->subMinutes(2));
                            }
                        }
                        $this->lighthouseResponse($renderer, $site, $report, $now, $host === 'ateliersorrell.example');
                        foreach ($checks as $key => $check) {
                            if (! in_array($key, ['uptime', 'performance'], true)) {
                                $this->evaluate($check, $check->type === CheckKey::BrokenLinks ? CarbonImmutable::now('UTC') : $now->subMinute(), history: app(DatabaseHistoryReader::class));
                            }
                        }
                        if ($host === 'booking.calloway-hotels.example') {
                            $start = $now->setTimezone($timezone)->addDays(2)->setTime(22, 0)->utc();
                            MaintenancePeriod::query()->create(['site_id' => $site->id, 'name' => 'Booking engine upgrade', 'timezone' => $timezone, 'starts_at' => $start, 'ends_at' => $start->addMinutes(90)]);
                        }
                        if (! in_array($host, ['shop.osterberghaus.example', 'api.kestrelmobility.example', 'lumenfilmfestival.example'], true)) {
                            NotificationSiteMute::query()->create(['destination_id' => $ownerChannel->id, 'site_id' => $site->id, 'created_at' => $now]);
                        }
                    }
                }
                // Zero outbound holds with workers stopped, or inside the one-day check deferral and one-week icon ceiling.
                // Promo sessions re-seed with `scripts/dev-up.sh agency` (this seeder returns early on existing agency data),
                // then stop Horizon and the dispatcher before recording.
                Check::query()->where('enabled', true)->update(['next_run_at' => $now->addDay()]);
                Log::info('Agency demo outbound proof', [
                    'http_transport' => FakeOutboundHttp::class, 'intercepted_http_requests' => count($http->requests),
                    'intercepted_hosts' => array_values(array_unique(array_map(static fn (array $request): string => (string) parse_url($request['request']->url, PHP_URL_HOST), $http->requests))),
                    'dns_transport' => FakeDnsResolver::class,
                ]);
            });
        } finally {
            Sleep::fake(false);
            foreach ($originals as $contract => $original) {
                app()->instance($contract, $original);
            }
        }
    }

    private function check(Site $site, CheckKey $key, CarbonImmutable $now): Check
    {
        $definition = app(CheckRegistry::class)->get($key)->definition();
        $config = $key === CheckKey::ApplicationHealth ? ['result_url' => $site->url.'/health', 'secret' => 'agency-fixture-secret'] : [];
        $check = Check::query()->create([
            'site_id' => $site->id, 'type' => $key, 'enabled' => false,
            'config' => $definition->configSchema->validateConfig($config), 'interval_seconds' => $definition->defaultInterval,
            'failure_threshold' => 1, 'next_run_at' => $now->addDay(), 'created_at' => $now->subDays(30),
        ]);
        app(CheckEnablement::class)->enable($check, $definition);

        return $check->refresh();
    }

    private function httpResponses(FakeOutboundHttp $http, string $host, float $seconds, bool $down = false, bool $broken = false): void
    {
        $http->respondUsing(static function (FetchRequest $request) use ($host, $seconds, $down, $broken): FetchResult {
            if (parse_url($request->url, PHP_URL_HOST) !== $host) {
                throw new \LogicException('Unexpected agency fixture HTTP target.');
            }
            $path = parse_url($request->url, PHP_URL_PATH);
            $path = is_string($path) && $path !== '' ? $path : '/';
            if ($down || ($broken && in_array($path, ['/programme-2025', '/press-kit', '/venues/riverside'], true))) {
                $status = $down ? 503 : 404;

                return new FetchResult($status, failure: new FetchFailure(FetchFailureKind::HttpStatus, $status), transfers: [['total_time' => $seconds]]);
            }
            $body = match ($path) {
                '/robots.txt' => "User-agent: *\nAllow: /\n",
                '/sitemap.xml' => '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://'.$host.'/</loc></url></urlset>',
                '/health' => json_encode(['finishedAt' => CarbonImmutable::now()->getTimestamp(), 'checkResults' => [
                    ['name' => 'database', 'label' => 'Database', 'status' => 'ok', 'shortSummary' => 'Connected'],
                    ['name' => 'queue', 'label' => 'Queue', 'status' => 'ok', 'shortSummary' => 'Running'],
                ]], JSON_THROW_ON_ERROR),
                default => '<html><body><h1>Welcome</h1>'.($broken ? '<a href="/programme-2025">Programme</a><a href="/press-kit">Press kit</a><a href="/venues/riverside">Riverside venue</a>' : '').'</body></html>',
            };

            return new FetchResult(200, $body, ['Content-Type' => [$path === '/sitemap.xml' ? 'application/xml' : 'text/html']], transfers: [['total_time' => $seconds]], effectiveUrl: $request->url);
        });
    }

    private function dnsResponses(FakeDnsResolver $dns, string $host): void
    {
        foreach ([null, 'ns.agency.example'] as $server) {
            foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA'] as $type) {
                $records = match ($type) {
                    'A' => [['host' => $host, 'type' => 'A', 'ip' => '203.0.113.10', 'ttl' => 3600, 'class' => 'IN']],
                    'NS' => [['host' => $host, 'type' => 'NS', 'target' => 'ns.agency.example', 'ttl' => 3600, 'class' => 'IN']],
                    default => [],
                };
                $dns->respondWith($host, $type, new DnsLookupResult($records, [], $server), $server);
            }
        }
    }

    /** @return list<CronCheck> */
    private function tasks(Check $check, string $host, CarbonImmutable $now): array
    {
        return CarbonImmutable::withTestNow($now->subDays(31), function () use ($check, $host): array {
            $definitions = $host === 'api.kestrelmobility.example'
                ? [['name' => 'Billing sync', 'type' => 'simple', 'frequency_in_minutes' => 15]]
                : [
                    ['name' => 'Nightly donation export', 'type' => 'cron', 'cron_expression' => '0 2 * * *', 'server_timezone' => 'Europe/London'],
                    ['name' => 'Gift Aid batch', 'type' => 'cron', 'cron_expression' => '0 3 * * 1', 'server_timezone' => 'Europe/London'],
                ];

            return array_map(fn (array $values): CronCheck => app(CronDefinitions::class)->create($check->id, [...$values, 'grace_time_in_minutes' => 10]), $definitions);
        });
    }

    /** @param array<string, mixed> $report */
    private function lighthouseResponse(FakeChromiumRenderer $renderer, Site $site, array $report, CarbonImmutable $at, bool $dropped): void
    {
        $report['requestedUrl'] = $report['finalUrl'] = $site->url;
        $report['fetchTime'] = $at->toIso8601String();
        /** @var array<string, array<string, mixed>> $categories */
        $categories = $report['categories'];
        $categories['performance']['score'] = $dropped ? 0.76 : 0.94;
        $report['categories'] = $categories;
        $renderer->respondWithLighthouse($site->url, new LighthouseReport($report, $site->url), ['desktop' => false, 'cpuSlowdownMultiplier' => 4, 'extraHeaders' => (object) []]);
    }

    private function certificate(string $host, int $days): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Could not create agency certificate key.');
        }
        /** @var \OpenSSLAsymmetricKey $key */
        $request = openssl_csr_new(['commonName' => $host], $key);
        if (! $request instanceof \OpenSSLCertificateSigningRequest) {
            throw new \RuntimeException('Could not create agency certificate request.');
        }
        $certificate = openssl_csr_sign($request, null, $key, $days);
        /** @var string $pem */
        $pem = '';
        if ($certificate === false || ! openssl_x509_export($certificate, $pem) || ! is_string($pem)) {
            throw new \RuntimeException('Could not create agency certificate.');
        }

        return $pem;
    }

    private function icon(Site $site, string $letters, string $color, CarbonImmutable $now): void
    {
        $image = imagecreatetruecolor(64, 64);
        if ($image === false) {
            throw new \RuntimeException('Could not create agency icon.');
        }
        $background = imagecolorallocate($image, (int) hexdec(substr($color, 1, 2)) & 255, (int) hexdec(substr($color, 3, 2)) & 255, (int) hexdec(substr($color, 5, 2)) & 255);
        $foreground = imagecolorallocate($image, 255, 255, 255);
        if ($background === false || $foreground === false) {
            throw new \RuntimeException('Could not allocate agency icon colours.');
        }
        imagefill($image, 0, 0, $background);
        imagestring($image, 5, (int) ((64 - strlen($letters) * imagefontwidth(5)) / 2), 24, $letters, $foreground);
        ob_start();
        if (imagepng($image) === false) {
            throw new \RuntimeException('Could not encode agency icon.');
        }
        $png = ob_get_clean();
        if ($png === false) {
            throw new \RuntimeException('Could not read agency icon.');
        }
        $hash = hash('sha256', $png);
        if (Storage::disk()->put('site-icons/'.$site->id.'/'.$hash.'.png', $png) === false) {
            throw new \RuntimeException('Could not store agency icon.');
        }
        // The daily icon refresh skips these local placeholders for one week; re-seed before that ceiling.
        $site->forceFill(['icon_hash' => $hash, 'icon_fetched_at' => $now, 'icon_attempted_at' => $now])->saveQuietly();
    }
}

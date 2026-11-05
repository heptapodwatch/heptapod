<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\RecordsReviewRuns;
use Heptapod\Actions\CreateEmailDestination;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Checks\CheckEnablement;
use Heptapod\Checks\History\DatabaseHistoryReader;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CertificateFetchResult;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\DomainRegistrationResult;
use Heptapod\Contracts\DomainRegistrationSource;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\LighthouseReport;
use Heptapod\Contracts\PublicSuffixList;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Client;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ExamplePublicSuffixList;
use Heptapod\Testing\FakeCertificateFetcher;
use Heptapod\Testing\FakeChromiumRenderer;
use Heptapod\Testing\FakeDomainRegistrationLookup;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;

final class DemoSeeder extends Seeder
{
    use RecordsReviewRuns { evaluate as private recordRun; }

    public const array SCENARIOS = ['calm', 'attention', 'incident', 'empty', 'many-sites', 'maintenance', 'stopped', 'agency'];

    public function run(string $scenario = 'attention'): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Demo fixtures are restricted to local and testing environments.');
        }
        if (! in_array($scenario, self::SCENARIOS, true)) {
            throw new \InvalidArgumentException('Unknown demo scenario: '.$scenario);
        }
        $this->call(DatabaseSeeder::class);
        if ($scenario === 'empty') {
            return;
        }
        if ($scenario === 'agency') {
            $this->call(AgencyDemoSeeder::class);

            return;
        }
        $withIncidents = in_array($scenario, ['attention', 'incident', 'stopped'], true);
        $originalHttp = app(OutboundHttp::class);
        $originalRenderer = app(ChromiumRenderer::class);
        $originalCertificateFetcher = app(CertificateFetcher::class);
        $originalDomainLookup = app(DomainRegistrationLookup::class);
        $originalSuffixes = app(PublicSuffixList::class);
        $http = new FakeOutboundHttp;
        $renderer = new FakeChromiumRenderer;
        $certificates = new FakeCertificateFetcher;
        $domains = new FakeDomainRegistrationLookup;
        app()->instance(OutboundHttp::class, $http);
        app()->instance(ChromiumRenderer::class, $renderer);
        app()->instance(CertificateFetcher::class, $certificates);
        app()->instance(DomainRegistrationLookup::class, $domains);
        app()->instance(PublicSuffixList::class, new ExamplePublicSuffixList);
        try {
            DB::transaction(function () use ($scenario, $withIncidents, $http, $renderer, $certificates, $domains): void {
                if (Site::query()->where('url', 'https://demo-01.example')->getQuery()->exists()) {
                    return;
                }
                $now = CarbonImmutable::now('UTC');
                $registry = app(CheckRegistry::class);
                $reportJson = file_get_contents(dirname(__DIR__, 2).'/addons/lighthouse/tests/Fixtures/mobile.json');
                if ($reportJson === false) {
                    throw new \RuntimeException('Demo Lighthouse report fixture is missing.');
                }
                /** @var array<string, mixed> $lighthouseReport */
                $lighthouseReport = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);
                $clients = [Client::query()->where('is_internal', true)->firstOrFail()];
                for ($i = 1; $i < 24; $i++) {
                    $clients[] = Client::query()->firstOrCreate(['slug' => sprintf('demo-client-%02d', $i)], [
                        'name' => sprintf('Demo Client %02d', $i), 'locale' => $i % 2 === 1 ? 'de' : 'en', 'timezone' => 'Europe/Berlin',
                    ]);
                }
                foreach (range(1, 64) as $index) {
                    $site = Site::query()->create([
                        'client_id' => $clients[($index - 1) % 24]->id,
                        'url' => sprintf('https://demo-%02d.example', $index),
                        'friendly_name' => match ($index) {
                            1 => 'Shop', 2 => 'Backup', 3 => 'API', 4 => 'Cinema', default => sprintf('Site %02d', $index),
                        },
                        'badge_id' => (string) Str::uuid(),
                    ]);
                    $tag = Tag::findOrCreate($index % 2 === 0 ? 'production' : 'content', 'sites', 'en');
                    if (! $tag instanceof Tag) {
                        throw new \LogicException('Demo site tag must be a tag model.');
                    }
                    $site->attachTag($tag);
                    $uptime = $this->check($site, CheckKey::Uptime, $now, $index === 1 ? 2 : 1);
                    if ($uptime instanceof Check) {
                        $http->respondUsing(fn (): FetchResult => new FetchResult(200, transfers: [['total_time' => 0.24]]));
                        $this->evaluate($uptime, $now->subMinutes(30));
                        if ($index === 3 && $withIncidents) {
                            $http->respondUsing(fn (): FetchResult => new FetchResult(503, transfers: [['total_time' => 0.24]]));
                            $this->evaluate($uptime, $now->subMinutes(20));
                            $http->respondUsing(fn (): FetchResult => new FetchResult(200, transfers: [['total_time' => 0.24]]));
                            $this->evaluate($uptime, $now->subMinutes(16));
                        }
                        if ($index === 1 && $scenario === 'incident') {
                            $http->respondUsing(fn (): FetchResult => new FetchResult(503, transfers: [['total_time' => 0.24]]));
                            $this->evaluate($uptime, $now->subMinutes(14));
                            $this->evaluate($uptime, $now->subMinutes(13));
                        }
                        if ($index === 4 && $withIncidents) {
                            $this->evaluate($uptime, $now->subMinutes(10), new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null,
                                error: new CheckError(\RuntimeException::class, 'Demo worker timeout')));
                            $this->evaluate($uptime, $now->subMinutes(9));
                        }
                        if ($index === 2 && $scenario === 'attention') {
                            CheckSnooze::query()->create(['check_id' => $uptime->id, 'starts_at' => $now->subHours(2), 'ends_at' => $now->subHour(), 'snoozed_by' => 'Demo operator', 'source' => 'demo']);
                        }
                    }
                    if ($scenario === 'attention' && in_array($index, [2, 5, 7, 8, 9], true)) {
                        $certificate = $this->check($site, CheckKey::CertificateHealth, $now);
                        if ($certificate instanceof Check) {
                            $certificates->respondWith(sprintf('demo-%02d.example', $index), new CertificateFetchResult([$this->reviewCertificate($index === 9 ? 45 : 6)], sprintf('demo-%02d.example', $index), 443, true));
                            $this->evaluate($certificate, $now->subMinutes(5));
                        }
                    }
                    if ($scenario === 'attention' && in_array($index, [2, 5, 7], true)) {
                        $domain = $this->check($site, CheckKey::Domain, $now);
                        if ($domain instanceof Check) {
                            $domains->respondWith('demo-'.sprintf('%02d', $index).'.example', new DomainRegistrationResult(DomainRegistrationSource::Rdap, [
                                'objectClassName' => 'domain', 'ldhName' => sprintf('demo-%02d.example', $index),
                                'status' => ['active'], 'events' => [['eventAction' => 'expiration', 'eventDate' => $now->addDays(18)->toIso8601String()]],
                            ], 'https://rdap.example/'));
                            $this->evaluate($domain, $now->subMinutes(5));
                        }
                    }
                    if ($index >= 2 && $index <= 20 && in_array($scenario, ['attention', 'incident', 'stopped', 'calm', 'maintenance'], true)) {
                        $cron = $this->check($site, CheckKey::Cron, $now);
                        if ($cron instanceof Check) {
                            CarbonImmutable::withTestNow($now->subMinutes(20), function () use ($cron, $index, $now, $withIncidents): void {
                                foreach (['Backup', 'Cleanup'] as $name) {
                                    $task = app(CronDefinitions::class)->create($cron->id, ['name' => $name.' '.$index, 'type' => 'simple', 'frequency_in_minutes' => 10, 'grace_time_in_minutes' => 5]);
                                    if (! $withIncidents || $index !== 2 || $name !== 'Backup') {
                                        $this->ping($task, $now->subMinutes(3));
                                    }
                                }
                            });
                            $this->evaluate($cron, $now->subMinutes(2));
                        }
                    }
                    if (in_array($index, [5, 8, 9, 10, 11, 12], true) && $withIncidents) {
                        $lighthouse = $this->check($site, CheckKey::Lighthouse, $now);
                        if ($lighthouse instanceof Check) {
                            if (in_array($index, [5, 8], true)) {
                                $this->evaluate($lighthouse, $now->subHours(2), new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null,
                                    error: new CheckError(\RuntimeException::class, 'Demo browser unavailable')));
                            } else {
                                if ($index === 9) {
                                    $lighthouse->update(['config' => $registry->get(CheckKey::Lighthouse)->definition()->configSchema->validateConfig([
                                        'notification_settings' => ['seo' => 90],
                                    ])]);
                                }
                                $renderer->respondWithLighthouse($site->url, new LighthouseReport($lighthouseReport, $site->url),
                                    ['desktop' => false, 'cpuSlowdownMultiplier' => 4, 'extraHeaders' => (object) []]);
                                $this->evaluate($lighthouse, $now->subHours(2));
                            }
                            if ($index === 5) {
                                $lighthouse->update(['next_run_at' => $now->subHours(3)]);
                            }
                        }
                    }
                    if ($index === 6) {
                        MaintenancePeriod::query()->create(['site_id' => $site->id, 'name' => 'Planned deploy', 'starts_at' => $now->addDays(2), 'ends_at' => $now->addDays(2)->addHours(2)]);
                    }
                    if ($index === 2 && $scenario === 'maintenance') {
                        MaintenancePeriod::query()->create(['site_id' => $site->id, 'name' => 'Active deploy', 'starts_at' => $now->subHour(), 'ends_at' => $now->addDay()]);
                    }
                    if ($index === 7 && $withIncidents && $uptime instanceof Check) {
                        $http->respondUsing(fn (): FetchResult => new FetchResult(200, transfers: [['total_time' => 2.4]]));
                        $this->evaluate($uptime, $now->subMinutes(7));
                        $performance = $this->check($site, CheckKey::Performance, $now);
                        if ($performance instanceof Check) {
                            $this->evaluate($performance, $now->subMinutes(4));
                        }
                    }
                    foreach ($site->checks as $check) {
                        if (($scenario === 'stopped' && $check->type === CheckKey::Uptime)
                            || ($index === 5 && $check->type === CheckKey::Lighthouse)) {
                            continue;
                        }
                        $check->update(['next_run_at' => $now->addSeconds($check->interval_seconds)]);
                    }
                    if ($scenario === 'stopped' && $uptime instanceof Check) {
                        $uptime->update(['next_run_at' => $now->subMinutes(40)]);
                    }
                }
                // One team mail channel that hears outages only, as the demo always did.
                $destination = app(CreateEmailDestination::class)(['address' => DatabaseSeeder::DEMO_EMAIL, 'label' => 'Demo alerts', 'locale' => 'en']);
                NotificationPreference::writeGrid($destination->id, [...array_map(static fn (): NotificationMode => NotificationMode::Off,
                    NotificationPreference::defaults(RecipientKind::Team)), 'outage' => NotificationMode::Immediate]);
                // Non-stopped heartbeats come only from the live scheduler and dispatcher that dev-up starts.
                // Stopped keeps the seeded stale values past the real warn thresholds, with no live writer.
                if ($scenario === 'stopped') {
                    DispatcherHeartbeat::query()->create(['id' => 'demo', 'last_seen_at' => $now->subMinutes(44)]);
                    Cache::store('redis')->forever(config()->string('heptapod.health.scheduler_cache_key'), $now->subMinutes(44)->toIso8601String());
                }
                User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->update(['last_seen_at' => $now->subHour()]);
            });
            if ($scenario === 'attention') {
                app(ReviewStatesSeeder::class)->run();
                app(CheckDetailReviewSeeder::class)->run();
            }
            // Live services run in every non-stopped scenario; pushing due dates out keeps the dispatcher from
            // claiming seeded checks, so fixture state holds for a review. This intentionally removes the overdue
            // signal for non-stopped scenarios; stopped keeps its seeded overdue checks.
            if ($scenario !== 'stopped') {
                Check::query()->where('enabled', true)->update(['next_run_at' => CarbonImmutable::now('UTC')->addDay()]);
            }
        } finally {
            app()->instance(OutboundHttp::class, $originalHttp);
            app()->instance(ChromiumRenderer::class, $originalRenderer);
            app()->instance(CertificateFetcher::class, $originalCertificateFetcher);
            app()->instance(DomainRegistrationLookup::class, $originalDomainLookup);
            app()->instance(PublicSuffixList::class, $originalSuffixes);
        }
    }

    private function reviewCertificate(int $validDays): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Could not create demo certificate key.');
        }
        /** @var \OpenSSLAsymmetricKey $key */
        $request = openssl_csr_new(['commonName' => 'demo.example'], $key);
        if (! $request instanceof \OpenSSLCertificateSigningRequest) {
            throw new \RuntimeException('Could not create demo certificate request.');
        }
        $certificate = openssl_csr_sign($request, null, $key, $validDays);
        /** @var string $pem */
        $pem = '';
        if ($certificate === false || ! openssl_x509_export($certificate, $pem) || ! is_string($pem)) {
            throw new \RuntimeException('Could not create demo certificate.');
        }

        return $pem;
    }

    private function check(Site $site, CheckKey $key, CarbonImmutable $now, int $threshold = 1): ?Check
    {
        $registry = app(CheckRegistry::class);
        if (! in_array($key, $registry->keys(), true)) {
            return null;
        }
        $definition = $registry->get($key)->definition();
        $check = Check::query()->create([
            'site_id' => $site->id, 'type' => $key, 'enabled' => false,
            'config' => $definition->configSchema->validateConfig([]),
            'interval_seconds' => $definition->defaultInterval, 'failure_threshold' => $threshold,
            'next_run_at' => $now->addSeconds($definition->defaultInterval),
        ]);
        app(CheckEnablement::class)->enable($check, $definition);

        return $check->refresh();
    }

    private function evaluate(Check $check, CarbonImmutable $at, ?CheckOutcome $outcome = null): void
    {
        $this->recordRun($check, $at, $outcome, app(DatabaseHistoryReader::class));
    }
}

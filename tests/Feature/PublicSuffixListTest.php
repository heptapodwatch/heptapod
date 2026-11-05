<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\DomainRegistrationResult;
use Heptapod\Contracts\DomainRegistrationSource;
use Heptapod\Contracts\PublicSuffixList;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Testing\FakeDnsResolver;
use Heptapod\Testing\FakeDomainRegistrationLookup;
use Illuminate\Filesystem\Filesystem;

it('uses one process-cached core list for DNS main-domain and domain registration resolution', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Dns]);
    $resources = sys_get_temp_dir().'/heptapod-psl-'.bin2hex(random_bytes(8)).'/resources';
    mkdir($resources, 0777, true);
    file_put_contents($resources.'/public_suffix_list.dat', "// ===BEGIN ICANN DOMAINS===\nco.psltest\n// ===END ICANN DOMAINS===\n// ===BEGIN PRIVATE DOMAINS===\nblog.co.psltest\n// ===END PRIVATE DOMAINS===\n");
    app()->setBasePath(dirname($resources));
    try {
        $suffixes = app(PublicSuffixList::class);
        $rules = $suffixes->rules();
        unlink($resources.'/public_suffix_list.dat');
        expect(app(PublicSuffixList::class)->rules())->toBe($rules);

        $dns = new FakeDnsResolver;
        $server = 'ns.fixture.example';
        foreach (['www.example.co.psltest', 'example.co.psltest'] as $host) {
            foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA'] as $type) {
                $records = $type === 'A' ? [['host' => $host, 'ttl' => 300, 'class' => 'IN', 'type' => 'A', 'ip' => '192.0.2.1']] : [];
                $dns->respondWith($host, $type, new DnsLookupResult($records, [], null));
                $dns->respondWith($host, $type, new DnsLookupResult($records, [], $server), $server);
            }
        }
        $dns->respondWith('co.psltest', 'NS', new DnsLookupResult([], [], null));
        $dns->respondWith('psltest', 'NS', new DnsLookupResult([
            ['host' => 'psltest', 'ttl' => 300, 'class' => 'IN', 'type' => 'NS', 'target' => $server],
        ], [], null));
        app()->instance(DnsResolver::class, $dns);
        app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.co.psltest', new DomainRegistrationResult(
            DomainRegistrationSource::Rdap,
            ['objectClassName' => 'domain', 'ldhName' => 'example.co.psltest', 'status' => ['active'], 'events' => [['eventAction' => 'expiration', 'eventDate' => '2028-10-02T00:00:00Z']]],
            'https://rdap.example.test',
        )));
        $now = CarbonImmutable::parse('2026-10-02T00:00:00Z');
        $this->travelTo($now);
        $context = new CheckContext($check->site_id, $check->id, 0, '11111111-1111-4111-8111-111111111111', 'https://www.example.co.psltest', $now, $now->addSeconds(120), ['monitor_main_domain' => true], [], null);

        $registry = app(CheckRegistry::class);
        $dnsOutcome = $registry->get(CheckKey::Dns)->execute($context);
        $domainOutcome = $registry->get(CheckKey::Domain)->execute(new CheckContext(
            $check->site_id, $check->id, 0, '11111111-1111-4111-8111-111111111111', $context->url,
            $now, $context->deadline, ['expires_soon_threshold_in_days' => 30], [], null,
        ));

        expect($dnsOutcome->result)->toBe(RunResult::Succeeded);
        expect($dnsOutcome->metrics->toArray()['record_count'])->toBe(2);
        expect($domainOutcome->result)->toBe(RunResult::Succeeded);
        expect($domainOutcome->evidence->toArray()['domain'])->toBe('example.co.psltest');
    } finally {
        app(Filesystem::class)->deleteDirectory(dirname($resources));
    }
});

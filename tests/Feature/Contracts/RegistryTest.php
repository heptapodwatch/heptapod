<?php

use Heptapod\Addons\ApplicationHealth\ApplicationHealthCheck;
use Heptapod\Addons\CertificateHealth\CertificateHealthCheck;
use Heptapod\Addons\Crawl\BrokenLinksCheck;
use Heptapod\Addons\Crawl\MixedContentCheck;
use Heptapod\Addons\Cron\CronCheckType;
use Heptapod\Addons\Dns\DnsCheck;
use Heptapod\Addons\Domain\DomainCheck;
use Heptapod\Addons\Lighthouse\LighthouseCheck;
use Heptapod\Addons\Performance\PerformanceCheck;
use Heptapod\Addons\Sitemap\SitemapCheck;
use Heptapod\Addons\Uptime\UptimeCheck;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckType;

it('resolves the singleton registry through the container and fails loudly for uninstalled addons', function (): void {
    $registry = app(CheckRegistry::class);
    expect($registry)->toBe(app(CheckRegistry::class));
    expect($registry->keys())->toContain(CheckKey::Uptime, CheckKey::Performance, CheckKey::CertificateHealth, CheckKey::Cron, CheckKey::Dns, CheckKey::ApplicationHealth, CheckKey::Domain, CheckKey::Sitemap, CheckKey::Lighthouse);
    expect($registry->get(CheckKey::Uptime))->toBeInstanceOf(UptimeCheck::class)
        ->not->toBe($registry->get(CheckKey::Uptime));
    expect($registry->get(CheckKey::Performance))->toBeInstanceOf(PerformanceCheck::class);
    expect($registry->get(CheckKey::CertificateHealth))->toBeInstanceOf(CertificateHealthCheck::class);
    expect($registry->get(CheckKey::Cron))->toBeInstanceOf(CronCheckType::class);
    expect($registry->get(CheckKey::ApplicationHealth))->toBeInstanceOf(ApplicationHealthCheck::class);
    expect($registry->get(CheckKey::Sitemap))->toBeInstanceOf(SitemapCheck::class);
    expect(fn () => $registry->register(UptimeCheck::class))->toThrow(LogicException::class);
    expect($registry->get(CheckKey::Dns))->toBeInstanceOf(DnsCheck::class);
    expect($registry->get(CheckKey::Domain))->toBeInstanceOf(DomainCheck::class);
    expect($registry->get(CheckKey::BrokenLinks))->toBeInstanceOf(BrokenLinksCheck::class);
    expect($registry->get(CheckKey::MixedContent))->toBeInstanceOf(MixedContentCheck::class);
    expect($registry->get(CheckKey::Lighthouse))->toBeInstanceOf(LighthouseCheck::class);
    expect(fn (): CheckType => new CheckRegistry(app())->get(CheckKey::Dns))->toThrow(LogicException::class);
});

it('keeps the complete loaded English and German catalogs in parity', function (): void {
    $loader = app('translation.loader');
    $en = array_keys($loader->load('en', '*', '*'));
    $de = array_keys($loader->load('de', '*', '*'));
    sort($en);
    sort($de);
    expect($en)->toBe($de);
});

it('loads addon translations in English and German with matching keys', function (): void {
    foreach (glob(base_path('addons/*/lang/en.json')) as $file) {
        $en = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $de = json_decode(file_get_contents(dirname($file).'/de.json'), true, flags: JSON_THROW_ON_ERROR);
        $enKeys = array_keys($en);
        $deKeys = array_keys($de);
        sort($enKeys);
        sort($deKeys);
        expect($enKeys)->toBe($deKeys);
        foreach (['en' => $en, 'de' => $de] as $locale => $translations) {
            app()->setLocale($locale);
            foreach ($translations as $key => $value) {
                expect(__($key))->toBe($value);
            }
        }
    }
});

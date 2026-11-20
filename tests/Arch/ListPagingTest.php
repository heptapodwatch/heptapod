<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\PaginationData;

// heptapod-ui-rules "Lists and paging": a list never caps silently. Every list an addon detail hands the UI is one page with its
// `<rows>_pagination` beside it (drawn by ListFooter), or bounded by nature for a named reason.

/** Detail lists that cannot grow past a small fixed size. @var array<string, string> "Class::$property" => reason */
const BoundedDetailLists = [
    'Heptapod\Addons\ApplicationHealth\ApplicationHealthDetailData::$attempts' => 'the latest three attempts, annotating History rows',
    'Heptapod\Addons\CertificateHealth\CertificateSnapshot::$certificate_checks' => 'the fixed certificate check list',
    'Heptapod\Addons\CertificateHealth\CertificateSnapshot::$certificate_chain_issuers' => 'the issuers of one certificate chain (at most 20 certificates)',
    'Heptapod\Addons\CertificateHealth\CertificateTimelineData::$observations' => 'annotates the History preview runs (Preview + link to Runs)',
    'Heptapod\Addons\Dns\DnsComparisonData::$current_issues' => 'issues of the seven record types',
    'Heptapod\Addons\Dns\DnsComparisonData::$timeline' => 'annotates the History preview runs (Preview + link to Runs)',
    'Heptapod\Addons\Dns\DnsSnapshot::$diagnostics' => 'diagnostics of one lookup of the seven record types',
    'Heptapod\Addons\Dns\DnsSnapshot::$authoritative_nameservers' => 'a zone\'s nameservers (capped at 100 in the snapshot)',
    'Heptapod\Addons\Dns\DnsSnapshot::$issues' => 'issues of the seven record types',
    'Heptapod\Addons\Dns\DnsSnapshot::$dns_records' => 'records of the seven record types (capped at 200 in the snapshot)',
    'Heptapod\Addons\Domain\DomainSnapshot::$nameservers' => 'a domain\'s registered nameservers',
    'Heptapod\Addons\Lighthouse\LighthouseHistoryData::$scores' => 'a chart series, not a list',
    'Heptapod\Addons\Sitemap\SitemapSnapshot::$notes' => 'coverage notes of one run',
    'Heptapod\Addons\Sitemap\SitemapSnapshot::$sitemapIndexes' => 'at most 20 sitemap indexes, five issues each',
    'Heptapod\Addons\Sitemap\SitemapSnapshot::$sitemaps' => 'at most 20 sitemaps, five issues each',
    'Heptapod\Addons\Uptime\UptimeHistoryData::$runs' => 'only the latest attempt is shown',
];

/** Catalog strings that announce a cap but describe no UI list. @var array<string, string> key => reason */
const CapAnnouncements = [
    'export.capped' => 'the Markdown/plain-text export file\'s row limit, not a list',
    'export.capped_unknown' => 'the Markdown/plain-text export file\'s row limit, not a list',
    'End of activity in this range. Older changes are not shown.' => 'the end of Activity\'s chosen period; a longer period shows more',
    'sitemap.presentation.legacy_capped' => 'older sitemap runs retained only their first issues; the rest no longer exist',
];

/** @return list<class-string<CheckDetailData>> */
function addonDetailClasses(): array
{
    $classes = [];
    foreach (glob(dirname(__DIR__, 2).'/addons/*/src/*.php') ?: [] as $file) {
        preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($file), $namespace);
        $class = ($namespace[1] ?? '').'\\'.pathinfo($file, PATHINFO_FILENAME);
        if (class_exists($class) && is_subclass_of($class, CheckDetailData::class) && ! new ReflectionClass($class)->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('every addon detail list is paged or bounded by nature', function (): void {
    $classes = addonDetailClasses();
    expect(count($classes))->toBeGreaterThan(10);
    $frontend = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js/components')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx') && ! str_ends_with($file->getFilename(), '.test.tsx')) {
            $frontend .= file_get_contents($file->getPathname());
        }
    }
    $violations = [];
    $seen = [];
    foreach ($classes as $class) {
        $constructor = new ReflectionClass($class)->getConstructor();
        $doc = (string) $constructor?->getDocComment();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $key = $class.'::$'.$name;
            if (! $parameter->isPromoted() || (string) $parameter->getType() !== 'array' || preg_match('/@param\s+list<.*\$'.$name.'\b/', $doc) !== 1) {
                continue;
            }
            $seen[] = $key;
            if (array_key_exists($key, BoundedDetailLists)) {
                continue;
            }
            $page = new ReflectionClass($class)->hasProperty($name.'_pagination') ? new ReflectionClass($class)->getProperty($name.'_pagination')->getType() : null;
            if (! $page instanceof ReflectionNamedType || $page->getName() !== PaginationData::class) {
                $violations[] = "{$key} is a list without {$name}_pagination: page it through ListPaging, or name its bound in BoundedDetailLists.";
            } elseif (! str_contains($frontend, '.'.$name.'_pagination')) {
                $violations[] = "{$key}: no component reads {$name}_pagination, so its ListFooter is missing.";
            }
        }
    }
    foreach (array_diff(array_keys(BoundedDetailLists), $seen) as $stale) {
        $violations[] = "Stale BoundedDetailLists entry {$stale}.";
    }
    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

test('no catalog string announces a silent list cap', function (): void {
    $root = dirname(__DIR__, 2);
    $violations = [];
    foreach ([...glob($root.'/lang/*/*.json') ?: [], ...glob($root.'/addons/*/lang/*.json') ?: []] as $catalog) {
        /** @var array<string, string> $strings */
        $strings = json_decode((string) file_get_contents($catalog), true, flags: JSON_THROW_ON_ERROR);
        foreach ($strings as $key => $value) {
            $cap = preg_match('/\b(showing (the )?(first|latest)|first (\d+|:\w+|\{\{\w+\}\})|not shown|(:shown|\{\{shown\}\}) (of|von))/i', $key.' '.$value) === 1;
            if ($cap && ! array_key_exists(preg_replace('/_(one|other)$/', '', $key) ?? $key, CapAnnouncements)) {
                $violations[] = str_replace($root.'/', '', $catalog).": \"{$key}\" announces a cap; page the list (ListFooter) instead.";
            }
        }
    }
    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

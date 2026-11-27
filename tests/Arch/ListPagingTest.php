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
    'Heptapod\Addons\Sitemap\SitemapSnapshot::$notes' => 'coverage notes of one run',
    'Heptapod\Addons\Sitemap\SitemapSnapshot::$sitemapIndexes' => 'at most 20 sitemap indexes, five issues each',
    'Heptapod\Addons\Sitemap\SitemapSnapshot::$sitemaps' => 'at most 20 sitemaps, five issues each',
    'Heptapod\Addons\Uptime\UptimeHistoryData::$runs' => 'only the latest attempt is shown',
    'Heptapod\Addons\Crawl\CrawlSnapshot::$notes' => 'coverage notes of one run (at most 32, CrawlPersistence)',
    'Heptapod\Addons\Lighthouse\LighthouseHistoryData::$scores' => 'a chart of at most 156 weekly averages (LighthousePersistence)',
];

const SelectChoices = 'the choices of a select or filter, which offers every option';
const TimeZones = 'the IANA time zone list';
const CuratedConfiguration = 'admin-curated configuration, shown in full (heptapod-ui-rules "Lists and paging")';

/** Page lists a user never walks page by page. @var array<string, string> "Class::$property" => reason */
const BoundedPageLists = [
    // shortcut: lane A (servers manage) pages this list; remove the entry when it merges.
    'Heptapod\Addons\Servers\Data\ServersPageData::$servers' => 'paged in the servers manage lane',
    'Heptapod\Data\Ui\ActivityPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\AddSitePageData::$checks' => 'the installed check types',
    'Heptapod\Data\Ui\AddSitePageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\AddSitePageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\AlertSensitivitySettingsPageData::$presets' => 'the fixed alert sensitivity presets',
    'Heptapod\Data\Ui\CheckTabPageData::$metrics' => 'one chart series per metric of the last day',
    'Heptapod\Data\Ui\CheckTabPageData::$phase_series' => 'one chart series per uptime timing phase',
    'Heptapod\Data\Ui\CheckTabPageData::$runs' => 'the History preview: the latest 20 runs and a link to Runs',
    'Heptapod\Data\Ui\ClientDetailPageData::$incidents' => 'the latest 20 incidents and a link to the client\'s Incidents (Preview + link)',
    'Heptapod\Data\Ui\ClientSettingsPageData::$avatar_sites' => SelectChoices,
    'Heptapod\Data\Ui\ClientSettingsPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\ClientSettingsPageData::$members' => CuratedConfiguration,
    'Heptapod\Data\Ui\ClientSettingsPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\ImportOhDearPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\ImportOhDearPageData::$sections' => 'the fixed import sections',
    'Heptapod\Data\Ui\ImportOhDearPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\IncidentDetailPageData::$related_incidents' => 'ten open incidents of the client and a link to them (Preview + link)',
    'Heptapod\Data\Ui\IncidentDetailPageData::$runs' => 'the incident\'s latest 20 runs and a link to Runs (Preview + link)',
    'Heptapod\Data\Ui\IncidentDetailPageData::$timeline' => 'the whole episode, collapsed into notification groups whose deliveries page on their own',
    'Heptapod\Data\Ui\IncidentsIndexPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\MaintenanceIndexPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\MaintenanceIndexPageData::$filter_clients' => SelectChoices,
    'Heptapod\Data\Ui\MaintenanceIndexPageData::$sites' => SelectChoices,
    'Heptapod\Data\Ui\MaintenanceIndexPageData::$tags' => SelectChoices,
    'Heptapod\Data\Ui\MaintenanceIndexPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\MembersPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\MembersPageData::$invitations' => CuratedConfiguration,
    'Heptapod\Data\Ui\MembersPageData::$invite_roles' => 'the fixed roles',
    'Heptapod\Data\Ui\MembersPageData::$members' => CuratedConfiguration,
    'Heptapod\Data\Ui\NotificationSettingsPageData::$channels' => CuratedConfiguration,
    'Heptapod\Data\Ui\NotificationSettingsPageData::$channel_types' => 'the installed channel types',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$check_types' => 'the installed check types',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\NotificationSettingsPageData::$grid' => 'one row per notification category',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$migration_report' => 'the one-off 2026-10-30 conversion report',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$sites' => SelectChoices,
    'Heptapod\Data\Ui\NotificationSettingsPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\ProfilePageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\RunsIndexPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\RunsIndexPageData::$current' => 'every running attempt (bounded by the worker pools) and the first 20 queued; queued_more counts the rest',
    'Heptapod\Data\Ui\RunsIndexPageData::$finished' => 'the board\'s latest 20 finished runs; the history below pages through all of them',
    'Heptapod\Data\Ui\SecurityPageData::$passkeys' => 'the user\'s own passkeys, shown in full',
    'Heptapod\Data\Ui\SiteOverviewPageData::$history' => 'one uptime bucket per day of the last 91 days',
    'Heptapod\Data\Ui\SiteOverviewPageData::$incidents' => 'the latest 20 incidents, read only to find the open uptime incident',
    'Heptapod\Data\Ui\SiteOverviewPageData::$repositories' => 'at most RepositoryUrl::MaxPerSite links',
    'Heptapod\Data\Ui\SiteOverviewPageData::$run_all_checks' => 'the checks of one site, one per check type',
    'Heptapod\Data\Ui\SiteSettingsPageData::$alert_sensitivity_presets' => 'the fixed alert sensitivity presets',
    'Heptapod\Data\Ui\SiteSettingsPageData::$check_options' => 'the installed check types',
    'Heptapod\Data\Ui\SiteSettingsPageData::$check_settings' => 'the checks of one site, one per check type',
    'Heptapod\Data\Ui\SiteSettingsPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\SiteSettingsPageData::$repositories' => 'at most RepositoryUrl::MaxPerSite links',
    'Heptapod\Data\Ui\SiteSettingsPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\SitesIndexPageData::$advisory_packages' => 'the picker\'s most severe packages; more_advisory_packages says others exist',
    'Heptapod\Data\Ui\SitesIndexPageData::$clients' => SelectChoices,
    'Heptapod\Data\Ui\SitesIndexPageData::$needs_attention' => 'the attention preview; Show all opens the paged list filtered to it',
    'Heptapod\Data\Ui\SitesIndexPageData::$tags' => SelectChoices,
    'Heptapod\Data\Ui\UpcomingIndexPageData::$clients' => SelectChoices,
];

/** Page lists paged by a property other than `<rows>_pagination`. @var array<string, string> "Class::$property" => pager property */
const PagedPageLists = [
    'Heptapod\Data\Ui\ActivityPageData::$expanded_events' => 'expanded_pagination',
    'Heptapod\Data\Ui\ClientDetailPageData::$sites' => 'pagination',
    'Heptapod\Data\Ui\ClientsIndexPageData::$clients' => 'pagination',
    'Heptapod\Data\Ui\IncidentNotificationPageData::$deliveries' => 'next_cursor',
    'Heptapod\Data\Ui\IncidentsIndexPageData::$incidents' => 'pagination',
    'Heptapod\Data\Ui\MaintenanceIndexPageData::$windows' => 'next_page',
    'Heptapod\Data\Ui\RunsHistoryPageData::$runs' => 'next_cursor',
    'Heptapod\Data\Ui\RunsIndexPageData::$runs' => 'next_cursor',
    'Heptapod\Data\Ui\SitesIndexPageData::$sites' => 'pagination',
    'Heptapod\Data\Ui\SitesIndexPageData::$sites_history' => 'pagination',
    'Heptapod\Data\Ui\UpcomingIndexPageData::$items' => 'next_page_url',
];

/** Catalog strings that announce a cap or progress count but describe no UI list. @var array<string, string> key => reason */
const CapAnnouncements = [
    'export.capped' => 'the Markdown/plain-text export file\'s row limit, not a list',
    'export.capped_unknown' => 'the Markdown/plain-text export file\'s row limit, not a list',
    'End of activity in this range. Older changes are not shown.' => 'the end of Activity\'s chosen period; a longer period shows more',
    'sitemap.presentation.legacy_capped' => 'older sitemap runs retained only their first issues; the rest no longer exist',
    ':done of :total sites processed' => 'import job progress counter, not a capped list',
    '{{ok}} of {{total}} sites are OK.' => 'portfolio health ratio headline, not a capped list',
    ':count of :total' => 'numeric progress/ratio format, not a capped list',
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

/**
 * Finds every `.<rows>_pagination` property actually passed into a `<ListFooter pagination={...}>` control
 * (directly, or through a component that forwards its `pagination` prop into `ListFooter`). Plain reads for
 * counts or exports do not satisfy this check.
 *
 * @return array<string, true>
 */
function listFooterPaginationBindings(string $componentsDir): array
{
    $sources = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($componentsDir)) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx') && ! str_ends_with($file->getFilename(), '.test.tsx')) {
            $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }
    // Extract each top-level component function's body so we can trace pagination prop forwarding to ListFooter.
    $functions = [];
    foreach ($sources as $source) {
        if (preg_match_all('/(?:export\s+)?function\s+([A-Z]\w*)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }
        foreach ($matches[1] as $index => [$name, $offset]) {
            $nextOffset = $matches[0][$index + 1][1] ?? strlen($source);
            $functions[$name] = substr($source, $offset, $nextOffset - $offset);
        }
    }
    $pagers = ['ListFooter' => true];
    do {
        $added = false;
        $tagPattern = implode('|', array_map(static fn (string $tag): string => preg_quote($tag, '/'), array_keys($pagers)));
        foreach ($functions as $name => $body) {
            if (isset($pagers[$name])) {
                continue;
            }
            if (preg_match('/<('.$tagPattern.')\b[\s\S]*?\bpagination=\{\s*pagination\b/s', $body) === 1) {
                $pagers[$name] = true;
                $added = true;
            }
        }
    } while ($added);

    $bound = [];
    $tagPattern = '/<('.implode('|', array_map(static fn (string $tag): string => preg_quote($tag, '/'), array_keys($pagers))).')\b/';
    foreach ($sources as $source) {
        if (preg_match_all($tagPattern, $source, $tags, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }
        foreach ($tags[0] as [$match, $offset]) {
            $cursor = $offset + strlen($match);
            $length = strlen($source);
            $braceDepth = 0;
            // Scan to the matching closing '>' of the JSX opening tag, ignoring '>' inside {...} expressions.
            while ($cursor < $length) {
                $char = $source[$cursor];
                if ($char === '{') {
                    $braceDepth++;
                } elseif ($char === '}') {
                    $braceDepth = max(0, $braceDepth - 1);
                } elseif ($char === '>' && $braceDepth === 0) {
                    break;
                }
                $cursor++;
            }
            $attrs = substr($source, $offset + strlen($match), $cursor - ($offset + strlen($match)));
            if (preg_match('/\bpagination=\{\s*([^}]+)\}/s', $attrs, $prop) === 1
                && preg_match_all('/\.([a-z0-9_]+_pagination)\b/', $prop[1], $props) > 0) {
                foreach ($props[1] as $propName) {
                    $bound[$propName] = true;
                }
            }
        }
    }

    return $bound;
}

/** @return list<class-string> every Inertia page data class (`*PageData`) in core and the addons */
function pageDataClasses(): array
{
    $root = dirname(__DIR__, 2);
    $classes = [];
    foreach ([...glob($root.'/app/Data/Ui/*PageData.php') ?: [], ...glob($root.'/addons/*/src/*PageData.php') ?: [], ...glob($root.'/addons/*/src/*/*PageData.php') ?: []] as $file) {
        preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($file), $namespace);
        $classes[] = ($namespace[1] ?? '').'\\'.pathinfo($file, PATHINFO_FILENAME);
    }

    return $classes;
}

/**
 * Every promoted list (a top-level `list<…>` constructor `@param` or property `@var`) is bounded, paged by the pager $pagers names, or has a
 * `<rows>_pagination` PaginationData beside it that a ListFooter binds.
 *
 * @param  list<class-string>  $classes
 * @param  array<string, string>  $bounded  "Class::$list" => reason
 * @param  array<string, string>  $pagers  "Class::$list" => the property that pages it
 * @return list<string>
 */
function unpagedListViolations(array $classes, array $bounded, string $boundedName, array $pagers = []): array
{
    $boundPagers = listFooterPaginationBindings(dirname(__DIR__, 2).'/resources/js');
    $violations = [];
    $seen = [];
    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $doc = (string) $constructor?->getDocComment();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $key = $class.'::$'.$name;
            $types = explode('|', ltrim((string) $parameter->getType(), '?'));
            if (! $parameter->isPromoted() || ! in_array('array', $types, true) || (preg_match('/@param\s+(?:[\w\\\\]+\|)*list<.*\$'.$name.'\b/', $doc) !== 1
                && preg_match('/@var\s+(?:[\w\\\\]+\|)*list</', (string) $reflection->getProperty($name)->getDocComment()) !== 1)) {
                continue;
            }
            $seen[] = $key;
            if (array_key_exists($key, $bounded)) {
                continue;
            }
            if (array_key_exists($key, $pagers)) {
                if (! $reflection->hasProperty($pagers[$key])) {
                    $violations[] = "{$key}: its pager {$pagers[$key]} is not a property of the class.";
                }

                continue;
            }
            $pageType = $reflection->hasProperty($name.'_pagination') ? (string) $reflection->getProperty($name.'_pagination')->getType() : '';
            if (! in_array(PaginationData::class, explode('|', ltrim($pageType, '?')), true)) {
                $violations[] = "{$key} is a list without {$name}_pagination: page it through ListPaging, or name its bound in {$boundedName}.";
            } elseif (! isset($boundPagers[$name.'_pagination'])) {
                $violations[] = "{$key}: no ListFooter (or ListFooter wrapper) binds pagination={{...{$name}_pagination}}.";
            }
        }
    }
    foreach (array_diff([...array_keys($bounded), ...array_keys($pagers)], $seen) as $stale) {
        $violations[] = "Stale catalogue entry {$stale}.";
    }

    return $violations;
}

test('every addon detail list is paged or bounded by nature', function (): void {
    $classes = addonDetailClasses();
    expect(count($classes))->toBeGreaterThan(10);
    $violations = unpagedListViolations($classes, BoundedDetailLists, 'BoundedDetailLists');
    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

test('every page data list is paged or bounded by nature', function (): void {
    $classes = pageDataClasses();
    expect(count($classes))->toBeGreaterThan(30);
    $violations = unpagedListViolations($classes, BoundedPageLists, 'BoundedPageLists', PagedPageLists);
    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

test('no catalog string announces a silent list cap', function (): void {
    $root = dirname(__DIR__, 2);
    $violations = [];
    foreach ([...glob($root.'/lang/*/*.json') ?: [], ...glob($root.'/addons/*/lang/*.json') ?: []] as $catalog) {
        /** @var array<string, string> $strings */
        $strings = json_decode((string) file_get_contents($catalog), true, flags: JSON_THROW_ON_ERROR);
        foreach ($strings as $key => $value) {
            $cap = preg_match('/\b(showing (the )?(first|latest)|first (\d+|:\w+|\{\{\w+\}\})|not shown|(:shown|\{\{shown\}\}|:count|\{\{count\}\}|:done|\{\{done\}\}) (of|von) (:\w+|\{\{\w+\}\}))/i', $key.' '.$value) === 1;
            if ($cap && ! array_key_exists(preg_replace('/_(one|other)$/', '', $key) ?? $key, CapAnnouncements)) {
                $violations[] = str_replace($root.'/', '', $catalog).": \"{$key}\" announces a cap; page the list (ListFooter) instead.";
            }
        }
    }
    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

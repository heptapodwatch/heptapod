<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\PaginationData;

// heptapod-ui-rules "Lists and paging": a list never caps silently. Every list an addon detail or a page hands the UI is one page with its
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
    'Heptapod\Addons\CertificateHealth\CertificateSnapshot::$certificate_details.additional_domains' => 'the subject alternative names of one certificate',
    'Heptapod\Addons\Dns\DnsComparisonData::$changes.added' => 'record changes of the seven record types (capped at 200 records in the snapshot)',
    'Heptapod\Addons\Dns\DnsComparisonData::$changes.removed' => 'record changes of the seven record types (capped at 200 records in the snapshot)',
    'Heptapod\Addons\Crawl\CrawlSnapshot::$notes' => 'coverage notes of one run (at most 32, CrawlPersistence)',
    'Heptapod\Addons\Lighthouse\LighthouseHistoryData::$scores' => 'a chart of at most 156 weekly averages (LighthousePersistence)',
];

const SelectChoices = 'the choices of a select or filter, which offers every option';
const RowClients = 'every visible client, from which the rows name their client; the client filter searches on the server';
const TimeZones = 'the IANA time zone list';
const CuratedConfiguration = 'admin-curated configuration, shown in full (heptapod-ui-rules "Lists and paging")';

/** Page lists a user never walks page by page. @var array<string, string> "Class::$property" => reason */
const BoundedPageLists = [
    'Heptapod\Addons\Servers\Data\ServerPageData::$history.points' => 'one chart point per step of the chosen window',
    'Heptapod\Data\Ui\ImportOhDearRunPageData::$importRun.channels' => 'the notification channels of one import, admin-curated configuration shown in full',
    'Heptapod\Data\Ui\ImportOhDearRunPageData::$importRun.common_notices' => 'one notice per notice kind the rows share',
    'Heptapod\Data\Ui\ImportOhDearRunPageData::$importRun.sections' => 'the fixed import sections',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$schedule.digest_weekdays' => 'at most the seven weekdays',
    'Heptapod\Data\Ui\SiteOverviewPageData::$run_all_result.results' => 'one result per check of the site',
    'Heptapod\Data\Ui\UpdatesSettingsPageData::$updates.releases' => 'the newest 20 releases the update check reads (docs/deploy/operations.md)',
    'Heptapod\Data\Ui\AddSitePageData::$checks' => 'the installed check types',
    'Heptapod\Data\Ui\AddSitePageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\AlertSensitivitySettingsPageData::$presets' => 'the fixed alert sensitivity presets',
    'Heptapod\Data\Ui\CheckTabPageData::$metrics' => 'one chart series per metric of the last day',
    'Heptapod\Data\Ui\CheckTabPageData::$phase_series' => 'one chart series per uptime timing phase',
    'Heptapod\Data\Ui\CheckTabPageData::$runs' => 'the History preview: the latest 20 runs and a link to Runs',
    'Heptapod\Data\Ui\ClientDetailPageData::$incidents' => 'the latest 20 incidents and a link to the client\'s Incidents (Preview + link)',
    'Heptapod\Data\Ui\ClientSettingsPageData::$members' => CuratedConfiguration,
    'Heptapod\Data\Ui\ClientSettingsPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\ImportOhDearPageData::$sections' => 'the fixed import sections',
    'Heptapod\Data\Ui\ImportOhDearPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\IncidentDetailPageData::$related_incidents' => 'ten open incidents of the client and a link to them (Preview + link)',
    'Heptapod\Data\Ui\IncidentDetailPageData::$runs' => 'the incident\'s latest 20 runs and a link to Runs (Preview + link)',
    'Heptapod\Data\Ui\IncidentDetailPageData::$timeline' => 'the whole episode, collapsed into notification groups whose deliveries page on their own',
    'Heptapod\Data\Ui\IncidentsIndexPageData::$clients' => RowClients,
    'Heptapod\Data\Ui\MaintenanceIndexPageData::$window_clients' => 'the clients the listed windows target, at most one per window on the page',
    'Heptapod\Data\Ui\MaintenanceIndexPageData::$tags' => SelectChoices,
    'Heptapod\Data\Ui\MaintenanceIndexPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\MembersPageData::$clients' => 'the clients the roster assigns, shown in full with it',
    'Heptapod\Data\Ui\MembersPageData::$invitations' => CuratedConfiguration,
    'Heptapod\Data\Ui\MembersPageData::$invite_roles' => 'the fixed roles',
    'Heptapod\Data\Ui\MembersPageData::$members' => CuratedConfiguration,
    'Heptapod\Data\Ui\NotificationSettingsPageData::$channels' => CuratedConfiguration,
    'Heptapod\Data\Ui\NotificationSettingsPageData::$channel_types' => 'the installed channel types',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$check_types' => 'the installed check types',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$grid' => 'one row per notification category',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$migration_report' => 'the one-off 2026-10-30 conversion report',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$muted_sites' => 'the sites the channels mute, admin-curated configuration shown in full',
    'Heptapod\Data\Ui\NotificationSettingsPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\ProfilePageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\RunsIndexPageData::$current' => 'every running attempt (bounded by the worker pools) and the first 20 queued; queued_more counts the rest',
    'Heptapod\Data\Ui\RunsIndexPageData::$finished' => 'the board\'s latest 20 finished runs; the history below pages through all of them',
    'Heptapod\Data\Ui\SecurityPageData::$passkeys' => 'the user\'s own passkeys, shown in full',
    'Heptapod\Data\Ui\SiteOverviewPageData::$history' => 'one uptime bucket per day of the last 91 days',
    'Heptapod\Data\Ui\SiteOverviewPageData::$repositories' => 'at most RepositoryUrl::MaxPerSite links',
    'Heptapod\Data\Ui\SiteOverviewPageData::$run_all_checks' => 'the checks of one site, one per check type',
    'Heptapod\Data\Ui\SiteSettingsPageData::$alert_sensitivity_presets' => 'the fixed alert sensitivity presets',
    'Heptapod\Data\Ui\SiteSettingsPageData::$check_options' => 'the installed check types',
    'Heptapod\Data\Ui\SiteSettingsPageData::$check_settings' => 'the checks of one site, one per check type',
    'Heptapod\Data\Ui\SiteSettingsPageData::$repositories' => 'at most RepositoryUrl::MaxPerSite links',
    'Heptapod\Data\Ui\SiteSettingsPageData::$timezones' => TimeZones,
    'Heptapod\Data\Ui\SitesIndexPageData::$advisory_packages' => 'the picker\'s most severe packages; more_advisory_packages says others exist',
    'Heptapod\Data\Ui\SitesIndexPageData::$clients' => RowClients,
    'Heptapod\Data\Ui\SitesIndexPageData::$needs_attention' => 'the attention preview; Show all opens the paged list filtered to it',
    'Heptapod\Data\Ui\SitesIndexPageData::$sites_history' => 'one history entry per row of the paged sites',
    'Heptapod\Data\Ui\SitesIndexPageData::$tags' => SelectChoices,
    'Heptapod\Data\Ui\UpcomingIndexPageData::$clients' => RowClients,
];

/** Cursor lists: no total, so no PaginationData; their next page is a cursor or URL. @var array<string, array{string, string}> "Class::$property" => [pager property, the control it is passed to] */
const PagedPageLists = [
    'Heptapod\Data\Ui\IncidentNotificationPageData::$deliveries' => ['next_cursor', 'Button'],
    'Heptapod\Data\Ui\RunsIndexPageData::$runs' => ['next_cursor', 'ListFooter'],
    'Heptapod\Data\Ui\UpcomingIndexPageData::$items' => ['next_page_url', 'ListFooter'],
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

/** @return array<string, string> every non-test `.tsx` under resources/js, path => source */
function tsxSources(): array
{
    static $sources = null;
    if ($sources === null) {
        $sources = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js')) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx') && ! str_ends_with($file->getFilename(), '.test.tsx')) {
                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    return $sources;
}

/**
 * `ListFooter` and every component of $sources that forwards its `pagination` prop into one.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function listFooterControls(array $sources): array
{
    // Extract each top-level component function's body so we can trace pagination prop forwarding to ListFooter.
    $functions = [];
    foreach ($sources as $source) {
        if (preg_match_all('/(?:export\s+)?function\s+([A-Z]\w*)\s*(?:<[^>]*>)?\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }
        foreach ($matches[1] as $index => [$name, $offset]) {
            $nextOffset = $matches[0][$index + 1][1] ?? strlen($source);
            $functions[$name] = substr($source, $offset, $nextOffset - $offset);
        }
    }
    $controls = ['ListFooter' => true];
    do {
        $added = false;
        $tagPattern = implode('|', array_map(static fn (string $tag): string => preg_quote($tag, '/'), array_keys($controls)));
        foreach ($functions as $name => $body) {
            if (isset($controls[$name])) {
                continue;
            }
            if (preg_match('/<('.$tagPattern.')\b[\s\S]*?\bpagination=\{\s*pagination\b/s', $body) === 1) {
                $controls[$name] = true;
                $added = true;
            }
        }
    } while ($added);

    return array_keys($controls);
}

/**
 * The attribute text of every `<Tag …>` opening tag of $tags in $source.
 *
 * @param  list<string>  $tags
 * @return list<string>
 */
function openingTagAttributes(string $source, array $tags): array
{
    $attributes = [];
    if (preg_match_all('/<('.implode('|', array_map(static fn (string $tag): string => preg_quote($tag, '/'), $tags)).')\b/', $source, $found, PREG_OFFSET_CAPTURE) === 0) {
        return [];
    }
    foreach ($found[0] as [$match, $offset]) {
        $start = $offset + strlen($match);
        $cursor = $start;
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
        $attributes[] = substr($source, $start, $cursor - $start);
    }

    return $attributes;
}

/**
 * Finds every `.<rows>_pagination` property that $within actually passes into a `<ListFooter pagination={...}>` control
 * (directly, or through a component of $sources that forwards its `pagination` prop into `ListFooter`). Plain reads for
 * counts or exports do not satisfy this check.
 *
 * @param  array<string, string>  $sources
 * @param  array<string, string>  $within
 * @return array<string, true>
 */
function listFooterPaginationBindings(array $sources, array $within): array
{
    $bound = [];
    $controls = listFooterControls($sources);
    foreach ($within as $source) {
        foreach (openingTagAttributes($source, $controls) as $attrs) {
            if (preg_match('/\bpagination=\{\s*([^}]+)\}/s', $attrs, $prop) === 1
                && preg_match_all('/(?<![\w$])(?:\w+\.)?([a-z0-9_]+_pagination)\b/', $prop[1], $props) > 0) {
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
 * The files that receive each page data class: the page an `Inertia::render('<page>', new <Class>(` renders, and every
 * file that types its props with the generated `Heptapod.…<Class>`.
 *
 * @param  list<class-string>  $classes
 * @return array<class-string, array<string, string>>
 */
function pageDataConsumers(array $classes): array
{
    $root = dirname(__DIR__, 2);
    $rendered = [];
    foreach ([...glob($root.'/app/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE) ?: [], ...glob($root.'/addons/*/src/{,*/,*/*/}*.php', GLOB_BRACE) ?: []] as $file) {
        preg_match_all("/Inertia::render\\(\\s*'([^']+)'\\s*,\\s*new\\s+(\\w+)\\(/", (string) file_get_contents($file), $renders, PREG_SET_ORDER);
        foreach ($renders as [, $page, $short]) {
            $rendered[$short][] = $root.'/resources/js/pages/'.$page.'.tsx';
        }
    }
    $consumers = [];
    foreach ($classes as $class) {
        $short = new ReflectionClass($class)->getShortName();
        $type = '/\b'.preg_quote(str_replace('\\', '.', $class), '/').'\b/';
        $consumers[$class] = array_filter(tsxSources(), static fn (string $source, string $path): bool => in_array($path, $rendered[$short] ?? [], true)
            || preg_match($type, $source) === 1, ARRAY_FILTER_USE_BOTH);
    }

    return $consumers;
}

/**
 * The top-level entries of the first `array{…}` shape in a PHPDoc type, key => type.
 *
 * @return array<string, string>
 */
function shapeEntries(string $type): array
{
    $start = strpos($type, 'array{');
    if ($start === false) {
        return [];
    }
    $entries = [];
    $depth = 0;
    $entry = '';
    for ($i = $start + 6, $length = strlen($type); $i < $length; $i++) {
        $char = $type[$i];
        if ($depth === 0 && ($char === ',' || $char === '}')) {
            if (preg_match('/^\s*(\w+)\??:\s*(.+)$/s', $entry, $match) === 1) {
                $entries[$match[1]] = trim($match[2]);
            }
            $entry = '';
            if ($char === '}') {
                break;
            }

            continue;
        }
        $depth += match ($char) {
            '{', '<', '(' => 1,
            '}', '>', ')' => -1,
            default => 0,
        };
        $entry .= $char;
    }

    return $entries;
}

/**
 * Every promoted list (a top-level `list<…>` constructor `@param` or property `@var`, or a top-level `list<…>` entry of an
 * `array{…}` shape param, keyed `Class::$param.entry`) is bounded, paged by the pager $pagers names and its consumers read,
 * or has a `<rows>_pagination` PaginationData beside it that a ListFooter in its consumers binds.
 *
 * @param  list<class-string>  $classes
 * @param  array<string, string>  $bounded  "Class::$list" => reason
 * @param  array<string, array{string, string}>  $pagers  "Class::$list" => [the property that pages it, its paging control]
 * @param  array<class-string, array<string, string>>|null  $consumers  the files receiving each class; null: any file
 * @return list<string>
 */
function unpagedListViolations(array $classes, array $bounded, string $boundedName, array $pagers = [], ?array $consumers = null): array
{
    $isList = static fn (string $type): bool => preg_match('/^(?:[\w\\\\]+\|)*list</', $type) === 1;
    $violations = [];
    $seen = [];
    $listFooterControls = listFooterControls(tsxSources());
    foreach ($classes as $class) {
        $within = $consumers === null ? tsxSources() : $consumers[$class];
        $boundPagers = listFooterPaginationBindings(tsxSources(), $within);
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $doc = (string) $constructor?->getDocComment();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $types = explode('|', ltrim((string) $parameter->getType(), '?'));
            if (! $parameter->isPromoted() || ! in_array('array', $types, true)) {
                continue;
            }
            $docType = preg_match('/@param\s+(.+?)\s+\$'.$name.'\b/', $doc, $param) === 1 ? $param[1]
                : (preg_match('/@var\s+(.+?)(?:\s|\*\/)/', (string) $reflection->getProperty($name)->getDocComment(), $var) === 1 ? $var[1] : '');
            // [key, pager property name, its PaginationData type or null]
            $lists = [];
            if ($isList($docType)) {
                $pageType = $reflection->hasProperty($name.'_pagination') ? (string) $reflection->getProperty($name.'_pagination')->getType() : '';
                $lists[] = [$class.'::$'.$name, $name, in_array(PaginationData::class, explode('|', ltrim($pageType, '?')), true)];
            } else {
                $entries = shapeEntries($docType);
                foreach ($entries as $entry => $entryType) {
                    if ($isList($entryType)) {
                        $lists[] = [$class.'::$'.$name.'.'.$entry, $entry, ($entries[$entry.'_pagination'] ?? '') === 'PaginationData'];
                    }
                }
            }
            foreach ($lists as [$key, $rows, $paged]) {
                $seen[] = $key;
                if (array_key_exists($key, $bounded)) {
                    continue;
                }
                if (array_key_exists($key, $pagers)) {
                    [$pager, $control] = $pagers[$key];
                    // The pager is read inside an attribute of its paging control's opening tag, e.g. pagination={pagination}.
                    $read = '/(?<![\'"\w.])(?:\w+\.)?'.preg_quote($pager, '/').'\b(?!\s*[,:=.\'"])/';
                    $tags = $control === 'ListFooter' ? $listFooterControls : [$control];
                    $passed = array_filter($within, static fn (string $source): bool => array_filter(openingTagAttributes($source, $tags),
                        static fn (string $attrs): bool => preg_match($read, $attrs) === 1) !== []);
                    if (! $reflection->hasProperty($pager)) {
                        $violations[] = "{$key}: its pager {$pager} is not a property of the class.";
                    } elseif ($passed === []) {
                        $violations[] = "{$key}: no file receiving {$class} passes its pager {$pager} to <{$control}>.";
                    }

                    continue;
                }
                if (! $paged) {
                    $violations[] = "{$key} is a list without {$rows}_pagination: page it through ListPaging, or name its bound in {$boundedName}.";
                } elseif (! isset($boundPagers[$rows.'_pagination'])) {
                    $violations[] = "{$key}: no ListFooter (or ListFooter wrapper) in a file receiving {$class} binds pagination={{...{$rows}_pagination}}.";
                }
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
    $violations = unpagedListViolations($classes, BoundedPageLists, 'BoundedPageLists', PagedPageLists, pageDataConsumers($classes));
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

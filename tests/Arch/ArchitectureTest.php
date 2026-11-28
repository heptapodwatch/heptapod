<?php

use Database\Seeders\AgencyDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReviewStatesSeeder;
use Database\Seeders\Support\RecordsReviewRuns;
use Heptapod\Actions\FetchSiteIcon;
use Heptapod\Actions\UpdateCheckConfig;
use Heptapod\Actions\UpdateSite;
use Heptapod\Addons\CertificateHealth\StreamCertificateFetcher;
use Heptapod\Addons\Crawl\CrawlCollector;
use Heptapod\Addons\Crawl\CrawlFailureHandler;
use Heptapod\Addons\Crawl\CrawlObserver;
use Heptapod\Checks\CauseSummary;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\ApiDocumentPart;
use Heptapod\Contracts\CheckKey;
use Heptapod\Events\EventFlapGroups;
use Heptapod\Events\EventPresenter;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Outbound\NativeTargetResolver;
use Heptapod\Providers\LocalFixtureServiceProvider;
use Heptapod\Queries\SiteDetailReader;
use Heptapod\Queries\SitesReader;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\RedactionContext;
use Heptapod\Support\SecretMerge;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

arch()->preset()->php();
arch()->preset()->security()->ignoring('tempnam');

arch('temporary files stay in bounded TLS diagnostics and icon decoding')
    ->expect('tempnam')->not->toBeUsed()
    ->ignoring([
        StreamCertificateFetcher::class, // tempnam creates a 0600 file; chainTrusted removes it in finally.
        FetchSiteIcon::class, // tempnam creates a 0600 input; icon decoding removes input and output in finally.
    ]);

arch('browser tests do not use the nonawaiting Playwright URL method', function (): void {
    foreach (glob(__DIR__.'/../Browser/*.php') ?: [] as $file) {
        expect(file_get_contents($file))->not->toContain('->waitForURL(');
    }
});

arch('browser tests do not block the Pest HTTP event loop while waiting', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../Browser'));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source)->not->toBeFalse();
        expect(preg_match('/\b(?:retry|sleep|usleep|time_nanosleep|time_sleep_until)\s*\(|\bSleep::/', $source))->toBe(0, $file->getPathname().' blocks the Pest HTTP server; use Amp\\delay() or waitForBrowserPath().');
    }
});
arch('testing fakes are only used by tests and other testing fakes')
    ->expect('Heptapod\\Testing')->toOnlyBeUsedIn(['Tests', 'Heptapod\\Testing'])
    // Dev-only fixtures drive real addon execution under the fake HTTP seam.
    ->ignoring([
        DemoSeeder::class, ReviewStatesSeeder::class, RecordsReviewRuns::class,
        AgencyDemoSeeder::class, // The agency scenario uses the same dev-only fixture seams.
        LocalFixtureServiceProvider::class, // Binds the recorded Oh Dear import client only in the local environment.
    ]);

$loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
$addonPrefixes = array_values(array_filter(array_keys($loader->getPrefixesPsr4()), fn (string $prefix): bool => str_starts_with($prefix, 'Heptapod\\Addons\\')));
$testRoots = [dirname(__DIR__), ...glob(dirname(__DIR__, 2).'/addons/*/tests', GLOB_ONLYDIR)];

arch('tests never use mocking helpers', function () use ($testRoots): void {
    foreach ($testRoots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                    $name = strtolower(ltrim($token[1], '\\'));
                    expect(explode('\\', $name)[0])->not->toBeIn(['mockery', 'mock', 'partialmock', 'spy']);
                }
            }
        }
    }
});

test('status glyphs outside shared primitives cannot replace a visible status', function (): void {
    $root = dirname(__DIR__, 2).'/resources/js';
    foreach (['pages', 'components', 'layouts'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)) as $file) {
            if (! $file->isFile() || preg_match('/\.tsx?$/', $file->getFilename()) !== 1
                || str_contains($file->getPathname(), '/components/ui/') || str_contains($file->getFilename(), '.test.')) {
                continue;
            }
            if ($file->getPathname() === $root.'/components/monitoring.tsx') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            expect($source)->not->toBeFalse();
            expect(preg_match('/<\s*(?:Check(?:Circle(?:2|Big)?|Icon)?|CircleCheck(?:Big)?|BadgeCheck|SquareCheck)\b/', $source))->toBe(0, $file->getPathname())
                ->and(preg_match('/[✓✔☑✅]/u', $source))->toBe(0, $file->getPathname());
            // These two icons confirm a finished run's OK result (the run family) or a copied value, not site health.
            if (in_array($file->getFilename(), ['run-family.tsx', 'copy-button.tsx'], true)) {
                continue;
            }
            expect(preg_match('/import\s*\{[^}]*\b(?:Check\w*|CircleCheck\w*|BadgeCheck|SquareCheck)\b[^}]*\}\s*from\s*[\'\"]lucide-react[\'\"]/s', $source))->toBe(0, $file->getPathname());
            preg_match_all('/import\s*\*\s*as\s+(\w+)\s+from\s*[\'\"]lucide-react[\'\"]/', $source, $namespaces);
            foreach ($namespaces[1] as $namespace) {
                expect(preg_match('/\b'.preg_quote($namespace, '/').'\.(?:Check\w*|CircleCheck\w*|BadgeCheck|SquareCheck)\b/', $source))->toBe(0, $file->getPathname());
            }
        }
    }
});

// DemoSeeder is local/testing-only and seeds the review fixture into the attention scenario on purpose.
arch('production seeders never invoke the review fixture')
    ->expect(DatabaseSeeder::class)->not->toUse(ReviewStatesSeeder::class);

arch('secret update entry points delegate merging to one helper')
    ->expect([UpdateSite::class, UpdateCheckConfig::class])->toUse(SecretMerge::class);

test('raw secret property access stays in credential handling boundaries', function (): void {
    $allowed = [
        'Actions/UpdateCheckConfig.php', 'Actions/UpdateSite.php',
        'Checks/CheckEnablement.php', 'Checks/CheckSettingsProjection.php', 'Checks/Execution/CheckExecutor.php',
        'Checks/HealthSettingsProbe.php',
        'Models/Check.php', 'Models/NotificationDestination.php',
        'Support/RedactionContext.php',
    ];
    $root = dirname(__DIR__, 2).'/app';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        expect($source)->not->toBeFalse();
        if (preg_match('/->(?:crawler_headers|config_secrets)\\b/', $source) === 1) {
            expect(substr($file->getPathname(), strlen($root) + 1))->toBeIn($allowed);
        }
    }
});

arch('storage and presentation share the credential redaction context')
    ->expect([TransactionalResultRecorder::class, CauseSummary::class, EventPresenter::class, NotificationMessage::class, SiteDetailReader::class])
    ->toUse(RedactionContext::class);

// One target per dataset row: Pest's not->toUse over an array of targets fails only when every target uses the dependency.
arch('core cause presentation does not interpret addon check keys', function (string $class) use ($addonPrefixes): void {
    expect($class)->not->toUse($addonPrefixes);
})->with([CauseSummary::class, EventPresenter::class, SitesReader::class, NotificationMessage::class]);

arch('core presentation contains no addon summary keys', function () use ($addonPrefixes): void {
    // Exact list: a new hit fails, and a moved key must be removed here.
    $grandfathered = [
        CauseSummary::class => [], SitesReader::class => [], NotificationMessage::class => [],
        EventPresenter::class => ['broken_links.no_verified_broken_one', 'broken_links.no_verified_broken_other', 'dns.records_changed',
            'sitemap.no_verified_issues_one', 'sitemap.no_verified_issues_other'],
        CheckRunPresentations::class => [],
        SiteDetailReader::class => ['crawl.start_redirect_credentials_origin', 'crawl.start_redirect_off_site', 'performance.hourly_average'],
        EventFlapGroups::class => ['dns.records_changed'],
    ];
    $keyPrefixes = array_unique([
        ...array_map(fn (string $prefix): string => Str::snake(class_basename(rtrim($prefix, '\\'))), $addonPrefixes),
        ...array_column(CheckKey::cases(), 'value'),
        'causes',
    ]);
    $pattern = '/\\b(?:'.implode('|', $keyPrefixes).')\\.[a-z_]+\\b/';
    // Core lang owns some sub-namespaces under these prefixes, e.g. causes.engine.*.
    $coreOwned = [];
    foreach (glob(dirname(__DIR__, 2).'/lang/en/*.json') as $langFile) {
        foreach (array_keys(json_decode((string) file_get_contents($langFile), true, flags: JSON_THROW_ON_ERROR)) as $key) {
            if (preg_match($pattern, $key, $match) === 1 && str_starts_with($key, $match[0])) {
                $coreOwned[] = $match[0];
            }
        }
    }
    foreach ($grandfathered as $class => $keys) {
        $file = new ReflectionClass($class)->getFileName();
        expect($file)->not->toBeFalse();
        $source = file_get_contents($file);
        expect($source)->not->toBeFalse();
        preg_match_all($pattern, $source, $matches);
        $hits = array_values(array_diff(array_unique($matches[0]), $coreOwned));
        sort($hits);
        expect($hits)->toBe($keys, $class);
    }
});

// Covers the API an addon routes itself (addons/*/routes/api.php) and documents as an ApiDocumentPart. An API whose routes
// and controllers still live in core, such as the cron tasks, is core text by this rule.
test('core names no operation, schema or tag of an addon-routed API, nor an addon switch', function () use ($addonPrefixes): void {
    $root = dirname(__DIR__, 2);
    $names = [];
    foreach (glob($root.'/addons/*/routes/api.php') as $routes) {
        preg_match_all("/->name\\('([^']+)'\\)/", (string) file_get_contents($routes), $matches);
        $names = [...$names, ...$matches[1]];
    }
    $tags = [];
    foreach (glob($root.'/addons/*/src', GLOB_ONLYDIR) as $sources) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sources, FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'implements ApiDocumentPart') && preg_match('/^namespace ([^;]+);/m', $source, $namespace) === 1) {
                /** @var ApiDocumentPart $part */
                $part = new ($namespace[1].'\\'.$file->getBasename('.php'));
                $names = [...$names, ...array_keys($part->components())];
                $tags = [...$tags, ...array_keys($part->tags())];
            }
        }
    }
    expect($names)->toContain('listServers', 'ServerItem')->and($tags)->toContain('Servers');
    $pattern = '/\b(?:'.implode('|', array_map(preg_quote(...), $names)).')\b|[\'"](?:'.implode('|', array_map(preg_quote(...), $tags)).')[\'"]/';
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches) > 0) {
            $hits[substr($file->getPathname(), strlen($root) + 1)] = array_values(array_unique($matches[0]));
        }
    }
    expect($hits)->toBe([]);
    // An addon's on/off switch lives in the addon's own settings group.
    $switches = array_filter(array_map(fn (ReflectionProperty $property): string => $property->getName(), new ReflectionClass(InstanceSettings::class)->getProperties()),
        fn (string $name): bool => array_any($addonPrefixes, fn (string $prefix): bool => str_starts_with($name, Str::snake(class_basename(rtrim($prefix, '\\'))).'_')));
    expect($switches)->toBe([]);
});

arch('new readers must materialize visibility before leaf lookups', function (): void {
    $grandfathered = [
        'EventsReader' => 1, // TODO: migrate to visibleSiteIds()
        'HistoryReader' => 1, // TODO: migrate to visibleSiteIds()
        'IncidentsReader' => 1, // TODO: migrate the list/detail scope to visibleSiteIds()
        // Not grandfathered: a bounded site read with no leaf lookups, joined to its client under the same visibility
        // predicate, so a site moved meanwhile never names a client the user cannot see.
        'PickerReader' => 1,
    ];
    $directClientReaders = ['SitesReader' => 1, 'ClientsReader' => 2, 'SiteDetailReader' => 1, 'SearchReader' => 1,
        'EventsReader' => 1, // Activity client filters must be limited to the user's visible clients.
        'PickerReader' => 1, // The client picker reads the visible clients, bounded by its search limit.
    ];
    foreach (glob(dirname(__DIR__, 2).'/app/Queries/*.php') as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toBeFalse();
        $reader = pathinfo($file, PATHINFO_FILENAME);
        expect(preg_match_all('/->visibleSites\s*\(/', $source))->toBe($reader === 'SitesReader' ? 1 : ($grandfathered[$reader] ?? 0), $reader)
            ->and(preg_match_all('/->visibleClients\s*\(/', $source))->toBe($directClientReaders[$reader] ?? 0, $reader);
    }
});

arch('core never depends on addons')
    ->expect($addonPrefixes)->toOnlyBeUsedIn($addonPrefixes)
    // Dev-only fixtures drive real addon execution under the fake HTTP seam.
    ->ignoring([
        DemoSeeder::class, ReviewStatesSeeder::class, RecordsReviewRuns::class,
        AgencyDemoSeeder::class, // The agency scenario runs real addons under dev-only fixture seams.
    ]);

$coreNamespaces = array_map(
    fn (string $path): string => 'Heptapod\\'.pathinfo($path, PATHINFO_FILENAME),
    array_filter(glob(dirname(__DIR__, 2).'/app/*'), fn (string $path): bool => ! in_array(basename($path), ['Contracts', 'Addons'], true)
        && (is_dir($path) || pathinfo($path, PATHINFO_EXTENSION) === 'php')),
);

arch('addons only depend on contracts and framework or vendor', function (string $addon) use ($coreNamespaces): void {
    expect($addon)->not->toUse([...$coreNamespaces, 'Database', 'Tests']);
})->with($addonPrefixes);

arch('addons fetch only through the core HTTP seam', function (string $addon): void {
    expect($addon)->not->toUse([Http::class, 'Illuminate\\Http\\Client', 'GuzzleHttp'])
        ->ignoring([
            'Heptapod\\Addons\\Channels', // TODO: migrate to core HTTP seam (signed-off notification transports).
            // spatie/crawler takes Guzzle client options; these classes build them from OutboundHttp::clientConfig().
            CrawlCollector::class, CrawlObserver::class, CrawlFailureHandler::class,
        ]);
})->with($addonPrefixes);

arch('concrete controllers are final')
    ->expect('Heptapod\Http\Controllers')->toBeFinal()->ignoring(Controller::class);

arch('controllers do not make HTTP requests or dispatch work')
    ->expect('Heptapod\Http\Controllers')
    ->not->toUse([Http::class, Queue::class, Bus::class, 'Illuminate\Http\Client', 'Illuminate\Contracts\Bus', 'Illuminate\Contracts\Queue', 'dispatch', 'dispatch_sync', 'dispatch_after_response']);

arch('check fetches never spawn resolver processes')
    ->expect('Heptapod\\Http\\Outbound')->not->toUse(Process::class)
    ->ignoring(NativeTargetResolver::class);

arch('only bounded operator probes and release checks opt into public-only DNS resolution', function (): void {
    $root = dirname(__DIR__, 2);
    foreach ([$root.'/app', ...glob($root.'/addons/*/src', GLOB_ONLYDIR)] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getPathname(), [
                $root.'/app/Checks/SiteProbe.php', $root.'/app/Actions/CheckForUpdates.php',
                $root.'/app/Actions/FetchSiteIcon.php', // Untrusted icon URLs use bounded public-only resolution.
            ], true)) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            expect($source)->not->toBeFalse()
                ->and(preg_match('/\\bpublicTargetsOnly\\s*:/', $source))->toBe(0, $file->getPathname());
        }
    }
});

arch('outbound curl callbacks do not throw into the shared multi loop', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Http/Outbound/GuzzleOutboundHttp.php');
    $start = strpos($source, '$options[\'sink\']');
    $end = strpos($source, '$promise = $next(', $start);
    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse();
    foreach (token_get_all('<?php '.substr($source, $start, $end - $start)) as $token) {
        if (is_array($token)) {
            expect($token[0])->not->toBe(T_THROW);
        }
    }
});

test('env is config-only and debug helpers are forbidden in all project PHP', function (): void {
    $root = dirname(__DIR__, 2);
    $paths = [...glob($root.'/*.php')];
    foreach (['app', 'bootstrap', 'routes', 'database', 'tests', 'addons', 'config', 'lang'] as $directory) {
        if (is_dir($root.'/'.$directory)) {
            $paths[] = $root.'/'.$directory;
        }
    }

    foreach ($paths as $path) {
        $files = is_dir($path)
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path))
            : [new SplFileInfo($path)];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $forbidden = ['dd', 'dump', 'ray'];
            if (! str_starts_with($file->getPathname(), $root.'/config/')
                && preg_match('~^'.preg_quote($root, '~').'/addons/[^/]+/config/.*\.php$~D', $file->getPathname()) !== 1) {
                $forbidden[] = 'env';
            }

            $tokens = array_values(array_filter(token_get_all(file_get_contents($file->getPathname())),
                static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            foreach ($tokens as $index => $token) {
                if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                    $name = strtolower(ltrim($token[1], '\\'));
                    // Process's env: named argument is not the env() config helper.
                    if ($name === 'env' && ($tokens[$index + 1] ?? null) !== '(') {
                        continue;
                    }
                    expect($name)->not->toBeIn($forbidden);
                }
            }
        }
    }
});

arch('addons never bundle a Public Suffix List', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/addons'));
    foreach ($files as $file) {
        expect($file->getFilename())->not->toContain('public_suffix_list');
    }
});

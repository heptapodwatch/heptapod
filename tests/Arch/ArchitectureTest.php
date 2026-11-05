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
use Heptapod\Checks\CauseSummary;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Events\EventPresenter;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Outbound\NativeTargetResolver;
use Heptapod\Providers\LocalFixtureServiceProvider;
use Heptapod\Queries\SiteDetailReader;
use Heptapod\Queries\SitesReader;
use Heptapod\Support\RedactionContext;
use Heptapod\Support\SecretMerge;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

arch()->preset()->php();
arch()->preset()->security()->ignoring('tempnam');

arch('temporary files stay in bounded TLS diagnostics and icon decoding')
    ->expect('tempnam')->not->toBeUsed()
    ->ignoring([
        StreamCertificateFetcher::class, // tempnam creates a 0600 file; chainTrusted removes it in finally.
        FetchSiteIcon::class, // tempnam creates a 0600 input; icon decoding removes input and output in finally.
    ]);

arch('tests never use Mockery')->expect('Tests')->not->toUse('Mockery');

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
$addonTestRoots = [];
foreach ($addonPrefixes as $prefix) {
    foreach ($loader->getPrefixesPsr4()[$prefix] as $source) {
        $tests = dirname($source).'/tests';
        if (is_dir($tests)) {
            $addonTestRoots[] = $tests;
        }
    }
}
$testRoots = [dirname(__DIR__), ...$addonTestRoots];

arch('mocking-helper scan includes every registered addon test root', function () use ($testRoots, $addonTestRoots): void {
    expect($addonTestRoots)->not->toBeEmpty()
        ->and($testRoots)->toContain(...$addonTestRoots);
});

arch('tests never use mocking helpers', function () use ($testRoots): void {
    foreach (array_unique($testRoots) as $root) {
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
            // These two icons confirm a completed run or a copied secret, not site health.
            if (in_array($file->getFilename(), ['foundation-primitives.tsx', 'two-factor-setup-modal.tsx'], true)) {
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

arch('production seeders never invoke the review fixture')
    ->expect([DatabaseSeeder::class, DemoSeeder::class])->not->toUse(ReviewStatesSeeder::class);

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

arch('core cause presentation does not interpret addon check keys')
    ->expect([CauseSummary::class, EventPresenter::class, SitesReader::class, NotificationMessage::class])->not->toUse($addonPrefixes);

arch('core presentation contains no addon summary keys', function (): void {
    foreach ([CauseSummary::class, EventPresenter::class, SitesReader::class, NotificationMessage::class] as $class) {
        $file = new ReflectionClass($class)->getFileName();
        expect($file)->not->toBeFalse();
        $source = file_get_contents($file);
        expect($source)->not->toBeFalse();
        expect(preg_match('/\\b(?:cron|performance|certificate_health|uptime|causes)\\.(?!error\\b)[a-z_]+\\b/', $source))->toBe(0, $class);
    }
});

arch('new readers must materialize visibility before leaf lookups', function (): void {
    $grandfathered = [
        'EventsReader' => 1, // TODO: migrate to visibleSiteIds()
        'HistoryReader' => 1, // TODO: migrate to visibleSiteIds()
        'IncidentsReader' => 1, // TODO: migrate the list/detail scope to visibleSiteIds()
    ];
    $directClientReaders = ['SitesReader' => 1, 'ClientsReader' => 2, 'SiteDetailReader' => 1, 'SearchReader' => 1,
        'EventsReader' => 1, // Activity client filters must be limited to the user's visible clients.
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

arch('addons only depend on contracts and framework or vendor')
    ->expect($addonPrefixes)->not->toUse([...$coreNamespaces, 'Database', 'Tests']);

arch('addons fetch only through the core HTTP seam')
    ->expect($addonPrefixes)->not->toUse([Http::class, 'Illuminate\\Http\\Client', 'GuzzleHttp'])
    ->ignoring([
        'Heptapod\\Addons\\Channels', // TODO: migrate to core HTTP seam (signed-off notification transports).
    ]);

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

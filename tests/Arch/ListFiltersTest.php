<?php

declare(strict_types=1);

use Heptapod\Addons\Servers\Http\ServersController;
use Heptapod\Addons\Servers\ServerReader;
use Heptapod\Http\Controllers\ActivityController;
use Heptapod\Http\Controllers\ClientsIndexController;
use Heptapod\Http\Controllers\IncidentsIndexController;
use Heptapod\Http\Controllers\MaintenanceController;
use Heptapod\Http\Controllers\RunsController;
use Heptapod\Http\Controllers\SitesIndexController;
use Heptapod\Http\Controllers\UpcomingController;
use Heptapod\Http\Requests\IncidentsFilterRequest;
use Heptapod\Queries\ClientsReader;
use Heptapod\Queries\EventsReader;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Queries\MaintenanceReader;
use Heptapod\Queries\RunsReader;
use Heptapod\Queries\SitesReader;
use Heptapod\Queries\UpcomingReader;

/** @return array<string, array{class-string, string}> */
function filteredListActions(): array
{
    return [
        'activity/index' => [ActivityController::class, '__invoke'],
        'clients/index' => [ClientsIndexController::class, '__invoke'],
        'incidents/index' => [IncidentsIndexController::class, '__invoke'],
        'maintenance/index' => [MaintenanceController::class, 'index'],
        'runs/index' => [RunsController::class, '__invoke'],
        'servers/index' => [ServersController::class, 'index'],
        'sites/index' => [SitesIndexController::class, '__invoke'],
        'upcoming/index' => [UpcomingController::class, '__invoke'],
    ];
}

/** @param class-string $class */
function listFilterMethodSource(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);
    $path = $reflection->getFileName();
    assert(is_string($path));
    $lines = file($path);
    assert(is_array($lines));

    return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
}

/** @return array<string, string> Filter key => literal entry source, excluding subsequent pager/sort arguments. */
function listFilterEntries(string $literal): array
{
    $tokens = PhpToken::tokenize('<?php '.$literal);
    $entries = [];
    $depth = 0;
    $key = null;
    foreach ($tokens as $token) {
        if ($depth === 1 && $key === null && $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $key = substr($token->text, 1, -1);
            $start = $token->pos;
        } elseif ($depth === 1 && $token->is(',')) {
            if ($key !== null) {
                $entries[$key] = substr($literal, $start - 6, $token->pos - $start);
                $key = null;
            }
        }
        $depth += match ($token->text) {
            '[', '(', '{' => 1,
            ']', ')', '}' => -1,
            default => 0,
        };
        if ($depth === 0 && $token->is(']')) {
            if ($key !== null) {
                $entries[$key] = substr($literal, $start - 6, $token->pos - $start);
            }

            return $entries;
        }
    }

    throw new LogicException('Expected a literal list filter declaration.');
}

it('keeps list actions and readers behind the declared request boundary', function (): void {
    foreach (filteredListActions() as [$controller, $action]) {
        $source = listFilterMethodSource($controller, $action);
        $methods = $controller === IncidentsIndexController::class ? 'user|header|listFilters' : 'user|header';
        $remaining = preg_replace('/(?:(?:ListFilters::read|ListPageSize::resolve)\(\s*\$request(?=\s*,)|\b(?:Request|IncidentsFilterRequest)\s+\$request\b|\$request\s*->\s*(?:'.$methods.')\s*\()/', '', $source);
        expect($remaining)->not->toMatch('/\$request\b|\brequest\s*\(|\bRequest::|\bValidator::|->validate\s*\(/');
    }

    foreach ([
        ClientsReader::class, EventsReader::class, IncidentsReader::class, MaintenanceReader::class,
        RunsReader::class, ServerReader::class, SitesReader::class, UpcomingReader::class,
    ] as $reader) {
        $path = (new ReflectionClass($reader))->getFileName();
        expect((string) file_get_contents((string) $path))->not->toMatch('/\$request\b|\brequest\s*\(|\bRequest::|use Illuminate\\\\Http\\\\Request\b/');
    }
});

it('binds every declared key to a predicate and passes the complete definition to the filter bar', function (): void {
    $root = dirname(__DIR__, 2);
    $pages = filteredListActions();
    $spatieReaders = [
        ClientsIndexController::class => [ClientsReader::class, 'index'],
        IncidentsIndexController::class => [IncidentsReader::class, 'filtered'],
        ServersController::class => [ServerReader::class, 'page'],
        SitesIndexController::class => [SitesReader::class, 'pageQuery'],
    ];
    foreach ($pages as $page => [$controller, $action]) {
        $source = listFilterMethodSource($controller, $action);
        expect($source)->toContain("Inertia::render('$page',");
        if ($controller === IncidentsIndexController::class) {
            expect($source)->toContain('$filters = $request->listFilters()', '$filters->data()');
            expect(listFilterMethodSource(IncidentsFilterRequest::class, 'rules'))->toContain('ListFilters::rules($this->definition()');
            expect(listFilterMethodSource(IncidentsFilterRequest::class, 'listFilters'))->toContain('ListFilters::fromValidated($this->definition()');
            $definition = strstr(listFilterMethodSource(IncidentsFilterRequest::class, 'definition'), "return [\n");
            $literal = substr((string) $definition, strlen('return '));
            $variable = '$filters';
        } else {
            $found = preg_match('/(\$\w+)\s*=\s*ListFilters::read\(\$request,\s*(\[.*)/s', $source, $match);
            expect($found)->toBe(1);
            [, $variable, $literal] = $match;
            expect($source)->toContain($variable.'->data()');
        }

        $entries = listFilterEntries($literal);
        expect($entries)->not->toBeEmpty("$page has no filter definitions.");
        foreach ($entries as $key => $entry) {
            if (isset($spatieReaders[$controller])) {
                expect($entry)->toMatch("/'predicate'\s*=>/", "$page: $key must declare its Spatie predicate.");
            } else {
                expect($source)->toMatch('/'.preg_quote($variable, '/').'->(?:value|integer)\(\''.preg_quote($key, '/').'\'\)/');
            }
            if ($controller !== RunsController::class) {
                expect($entry)->not->toMatch('/FilterSlot::(?:History|HistoryRange)\b/');
            }
        }
        if (isset($spatieReaders[$controller])) {
            expect(listFilterMethodSource(...$spatieReaders[$controller]))->toContain('$filters->query(');
        }

        $view = (string) file_get_contents("$root/resources/js/pages/$page.tsx");
        expect($view)->not->toMatch('/<Filter(?:Bar|Chip|Combobox|SelectTrigger)\b/');
        expect($view)->toMatch('/<ListFilterBar\b\s+filters=\{(?:filters|draftFilters|withFilterValues\(filters, draft\))\}/');
        if ($controller === RunsController::class) {
            expect($view)->toMatch('/<HistoryFilters\b\s+filters=\{filters\}/');
        }
    }
});

it('inventories pages with list filters, including controls outside FilterBar', function (): void {
    $root = dirname(__DIR__, 2).'/resources/js/pages';
    $pages = filteredListActions();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();
        if (! str_ends_with($path, '.tsx') || str_ends_with($path, '.test.tsx')) {
            continue;
        }
        $page = substr($path, strlen($root) + 1, -4);
        if ($page === 'dev/components') {
            continue;
        }
        $source = (string) file_get_contents($path);
        $filterControl = preg_match('/<(?:ListFilterBar|FilterBar|FilterChip|FilterCombobox|FilterSelectTrigger)\b|\buseLiveSearch\s*\(|\btype=[\'"]search[\'"]/', $source) === 1;
        $listWithQueryControl = preg_match('/<(?:ListFooter|InfiniteScroll|DataTable)\b/', $source) === 1
            && preg_match('/<SegmentedControl\b|\b(?:filter|filters):\s*\{|mergeQuery\s*:/', $source) === 1;
        if ($filterControl || $listWithQueryControl) {
            expect($pages)->toHaveKey($page);
        }
    }
});

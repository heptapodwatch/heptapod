<?php

declare(strict_types=1);

use Heptapod\Addons\Servers\Http\ServersController;
use Heptapod\Http\Controllers\ActivityController;
use Heptapod\Http\Controllers\ClientsIndexController;
use Heptapod\Http\Controllers\IncidentsIndexController;
use Heptapod\Http\Controllers\MaintenanceController;
use Heptapod\Http\Controllers\RunsController;
use Heptapod\Http\Controllers\SitesIndexController;
use Heptapod\Http\Controllers\UpcomingController;
use Heptapod\Http\Requests\IncidentsFilterRequest;

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

it('validates list filters through a declaration and passes the complete result to the filter bar', function (): void {
    $root = dirname(__DIR__, 2);
    $pages = filteredListActions();
    foreach ($pages as $page => [$controller, $action]) {
        $source = listFilterMethodSource($controller, $action);
        expect($source)->not->toMatch('/\bValidator::|->validate\s*\(|\$request->(?:input|string|integer|boolean|enum|filled|query)\s*\(\s*[\'"\$]/');
        expect($source)->toContain("Inertia::render('$page',");
        if ($controller === IncidentsIndexController::class) {
            expect($source)->toContain('$request->listFilters()->data()');
            expect(listFilterMethodSource(IncidentsFilterRequest::class, 'rules'))->toContain('ListFilters::rules($this->definition()');
            expect(listFilterMethodSource(IncidentsFilterRequest::class, 'listFilters'))->toContain('ListFilters::fromValidated($this->definition()');
        } else {
            expect($source)->toMatch('/(\$\w+)\s*=\s*ListFilters::read\(\$request,\s*\[.*\1->data\(\)/s');
        }

        $view = (string) file_get_contents("$root/resources/js/pages/$page.tsx");
        expect($view)->not->toMatch('/<Filter(?:Bar|Chip|Combobox|SelectTrigger)\b/');
        expect($view)->toMatch('/<ListFilterBar\b\s+filters=\{(?:filters|draftFilters|withFilterValues\(filters, draft\))\}/');
        if ($controller === RunsController::class) {
            expect($view)->toMatch('/<HistoryFilters\b\s+filters=\{filters\}/');
        }
    }

    foreach (glob("$root/resources/js/pages/*/*.tsx") ?: [] as $path) {
        $page = substr($path, strlen("$root/resources/js/pages/"), -4);
        if ($page !== 'dev/components' && preg_match('/<List?FilterBar\b|<FilterBar\b/', (string) file_get_contents($path)) === 1) {
            expect($pages)->toHaveKey($page);
        }
    }
});

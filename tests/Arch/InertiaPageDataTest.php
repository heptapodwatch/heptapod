<?php

use Tests\Arch\ArchDrift;
use Tests\Arch\InertiaRenders;

// PHP enforces a page's shape only when its props are built through the generated *PageData contract.
test('every Inertia page renders through its PageData contract', function (): void {
    $renders = InertiaRenders::all();
    expect(count($renders))->toBeGreaterThan(30);
    $violations = [];
    foreach ($renders as $render) {
        if ($render['kind'] === 'raw' && ! array_key_exists($render['component'], ArchDrift::PagesWithoutPageData)) {
            $violations[] = "{$render['file']}:{$render['line']} renders {$render['component']} with raw props; pass its *PageData object.";
        }
    }
    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

// The exception list may only shrink: a page that moved onto its PageData must leave the list.
test('every page without PageData is still rendered with raw props', function (): void {
    $raw = array_column(array_filter(InertiaRenders::all(), static fn (array $render): bool => $render['kind'] === 'raw'), 'component');
    foreach (array_keys(ArchDrift::PagesWithoutPageData) as $component) {
        expect(in_array($component, $raw, true))->toBeTrue("Stale entry {$component}: remove it from ArchDrift::PagesWithoutPageData.");
    }
});

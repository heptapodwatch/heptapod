<?php

use Illuminate\Support\Arr;

test('core JSON translations exist only inside locale area directories', function (): void {
    expect(glob(dirname(__DIR__, 2).'/lang/*.json'))
        ->toBeEmpty('Put core translations in lang/{en,de}/<area>.json; see docs/ui.md.');
});

// Framework translations and application JSON must both exist; an empty lang/ cannot pass.
test('English and German translation files have identical non-empty keys', function (): void {
    $langPath = dirname(__DIR__, 2).'/lang';
    $englishFiles = glob($langPath.'/en/*.php');
    $germanFiles = glob($langPath.'/de/*.php');

    expect($englishFiles)->not->toBeEmpty()
        ->and($germanFiles)->not->toBeEmpty()
        ->and(array_map(basename(...), $englishFiles))->toBe(array_map(basename(...), $germanFiles));

    $englishAreas = glob($langPath.'/en/*.json');
    $germanAreas = glob($langPath.'/de/*.json');
    expect($englishAreas)->not->toBeEmpty()
        ->and(array_map(basename(...), $englishAreas))->toBe(array_map(basename(...), $germanAreas));

    $pairs = [];
    $allKeys = [];
    foreach ($englishAreas as $file) {
        $area = basename($file);
        $english = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $german = json_decode(file_get_contents($langPath.'/de/'.$area), true, flags: JSON_THROW_ON_ERROR);
        $duplicates = array_intersect($allKeys, array_keys($english));
        expect($duplicates)->toBeEmpty();
        $allKeys = array_merge($allKeys, array_keys($english));
        $pairs[$area] = [$english, $german];
    }

    foreach ($englishFiles as $file) {
        $pairs[basename($file)] = [require $file, require $langPath.'/de/'.basename($file)];
    }

    foreach ($pairs as [$english, $german]) {
        expect($english)->toBeArray()->not->toBeEmpty()
            ->and($german)->toBeArray()->not->toBeEmpty();

        $englishKeys = array_keys(Arr::dot($english));
        $germanKeys = array_keys(Arr::dot($german));
        sort($englishKeys);
        sort($germanKeys);

        expect($englishKeys)->toBe($germanKeys);
    }
});

test('Laravel resolves JSON translations from every core area in both locales', function (): void {
    foreach (['en', 'de'] as $locale) {
        foreach (glob(lang_path("{$locale}/*.json")) as $file) {
            $lines = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $key = array_key_first($lines);
            expect(__($key, [], $locale))->toBe($lines[$key]);
        }
    }
});

<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckDetailProvider;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;

it('generates the detail union from the real installed registry', function (bool $removeAddon): void {
    $installed = app(CheckRegistry::class);
    $detailKeys = array_values(array_filter($installed->keys(), fn (CheckKey $key): bool => $installed->get($key)->definition()->detailDataClass !== null));
    expect($detailKeys)->not->toBeEmpty();
    $omitted = $removeAddon ? $detailKeys[0] : null;
    $registry = new CheckRegistry(app());
    $expected = [];
    foreach ($installed->keys() as $key) {
        if ($key === $omitted) {
            continue;
        }
        $type = $installed->get($key);
        $registry->register($type::class);
        $class = $type->definition()->detailDataClass;
        if ($class !== null) {
            expect($type)->toBeInstanceOf(CheckDetailProvider::class);
            $expected[] = str_replace('\\', '.', $class)." & {\nreadonly check_type: '".$key->value."',\n}";
        }
    }
    app()->instance(CheckRegistry::class, $registry);
    $directory = sys_get_temp_dir().'/heptapod-types-'.Str::uuid();
    $config = app(TypeScriptTransformerConfig::class);
    app()->instance(TypeScriptTransformerConfig::class, new TypeScriptTransformerConfig(...array_replace(get_object_vars($config), ['outputDirectory' => $directory])));

    try {
        $this->artisan('typescript:transform', ['--no-interaction' => true])->assertSuccessful();
        $generated = File::get($directory.'/generated.d.ts');
        expect($generated)->toContain('export type CheckDetailData = '.implode(' | ', $expected).';');
    } finally {
        File::deleteDirectory($directory);
    }
})->with(['all addons' => false, 'one detail addon absent' => true]);

<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->singleton(TypeScriptTransformerConfig::class, fn (): TypeScriptTransformerConfig => (new TypeScriptTransformerConfigFactory)
    ->extension(new LaravelDataTypeScriptTransformerExtension)
    ->transformer(EnumTransformer::class)
    ->transformDirectories(app_path('Contracts'))
    ->outputDirectory(base_path('tests/Fixtures'))
    ->withoutManifest()
    ->writer(new GlobalNamespaceWriter('Contracts.generated.d.ts'))
    ->get());
$status = Artisan::call('typescript:transform');
if ($status !== 0) {
    exit($status);
}
new Process(['node_modules/.bin/vp', 'fmt', 'tests/Fixtures/Contracts.generated.d.ts'], base_path())->mustRun();

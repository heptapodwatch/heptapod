<?php

declare(strict_types=1);

namespace Heptapod\Providers;

use Heptapod\Data\CheckDetailTransformer;
use Heptapod\Data\PageDataTransformer;
use Heptapod\Data\SiteStatusTypes;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;

final class TypeScriptTransformerServiceProvider extends TypeScriptTransformerApplicationServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $addonDirectories = glob(base_path('addons/*/src'));
        if ($addonDirectories === false) {
            throw new \RuntimeException('Cannot discover addon type directories.');
        }
        $config
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->prependTransformer(new PageDataTransformer)
            ->prependTransformer($this->app->make(CheckDetailTransformer::class))
            ->transformer(EnumTransformer::class)
            ->provider(new SiteStatusTypes)
            ->transformDirectories(app_path(), ...$addonDirectories)
            ->outputDirectory(resource_path('js/types'))
            ->withoutManifest()
            ->writer(new GlobalNamespaceWriter('generated.d.ts'));
    }
}

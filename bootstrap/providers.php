<?php

declare(strict_types=1);

use Heptapod\Providers\AppServiceProvider;
use Heptapod\Providers\FortifyServiceProvider;
use Heptapod\Providers\LocalFixtureServiceProvider;
use Heptapod\Providers\OpenApiServiceProvider;
use Heptapod\Providers\TypeScriptTransformerServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    LocalFixtureServiceProvider::class,
    OpenApiServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
];

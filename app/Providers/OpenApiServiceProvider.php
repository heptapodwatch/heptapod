<?php

declare(strict_types=1);

namespace Heptapod\Providers;

use Dedoc\Scramble\Scramble;
use Heptapod\Support\OpenApi\DocumentApi;
use Heptapod\Support\OpenApi\DocumentApiOperation;
use Illuminate\Support\ServiceProvider;

/**
 * Scramble generates docs/openapi.json (`scramble:export`). The app serves that committed file at
 * GET /api/openapi.json, so Scramble's own UI and JSON routes stay off.
 */
final class OpenApiServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        Scramble::throwOnError();
        Scramble::configure()
            ->withOperationTransformers(DocumentApiOperation::class)
            ->withDocumentTransformers(DocumentApi::class);
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Tag;
use Heptapod\Support\OpenApi\ApiContract;
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
            ->withTags(array_map(static fn (string $name, string $description): Tag => new Tag($name, $description), array_keys(ApiContract::tags()), ApiContract::tags()), createMissingParents: false)
            ->withOperationTransformers(DocumentApiOperation::class)
            ->withDocumentTransformers(DocumentApi::class);
    }
}

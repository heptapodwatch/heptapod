<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/** Serves the committed, drift-checked OpenAPI document; it describes the API and contains no monitoring data. */
final class OpenApiDocumentController extends Controller
{
    public function __invoke(): Response
    {
        return response(File::get(base_path('docs/openapi.json')), 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=300']);
    }
}

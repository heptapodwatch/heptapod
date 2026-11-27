<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Contracts\ServersAccess;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Support\OpenApi\DocumentApi;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/** Serves the committed, drift-checked OpenAPI document; it describes the API and contains no monitoring data. */
#[Group('Account')]
final class OpenApiDocumentController extends Controller
{
    public function __invoke(ServersAccess $servers): Response
    {
        $document = File::get(base_path('docs/openapi.json'));

        return response($servers->enabled() ? $document : $this->withoutServers($document), 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=300']);
    }

    /**
     * Switched off, servers do not exist: the document drops the Servers tag and its operations, the Server* schemas, the
     * monitor's `server` and the bearer description's lines on the servers abilities.
     */
    private function withoutServers(string $json): string
    {
        // Objects, not arrays, so empty JSON objects stay `{}`.
        /** @var object{paths: \stdClass, tags: list<object{name: string}>, components: object{schemas: \stdClass, securitySchemes: \stdClass}} $document */
        $document = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $pathItems = $document->paths;
        /** @var array<string, \stdClass> $paths */
        $paths = get_object_vars($pathItems);
        foreach ($paths as $path => $methods) {
            /** @var array<string, object{tags: list<string>}> $operations */
            $operations = get_object_vars($methods);
            foreach ($operations as $method => $operation) {
                if (in_array('Servers', $operation->tags, true)) {
                    unset($methods->{$method});
                }
            }
            if (get_object_vars($methods) === []) {
                unset($pathItems->{$path});
            }
        }
        $components = $document->components;
        $schemas = $components->schemas;
        foreach (array_keys(get_object_vars($schemas)) as $name) {
            if (str_starts_with($name, 'Server')) {
                unset($schemas->{$name});
            }
        }
        /** @var object{properties: \stdClass} $monitor */
        $monitor = $schemas->MonitorResource;
        unset($monitor->properties->server);
        /** @var \stdClass $bearer */
        $bearer = $components->securitySchemes->bearerAuth;
        /** @var string $description */
        $description = $bearer->description;
        $bearer->description = implode("\n", array_filter(explode("\n", $description),
            static fn (string $line): bool => ! str_starts_with($line, DocumentApi::ServersLinePrefix) && ! str_starts_with($line, '- `servers:')));
        $tags = array_values(array_filter($document->tags, static fn (object $tag): bool => $tag->name !== 'Servers'));

        return json_encode((object) [...get_object_vars($document), 'tags' => $tags], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Contracts\ApiDocumentPart;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Support\OpenApi\ApiContract;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;

/**
 * Serves the committed, drift-checked OpenAPI document; it describes the API and contains no monitoring data. The file
 * holds every bundled addon. Served, it holds only the operations this instance routes, without the ones of a
 * switched-off addon.
 */
#[Group('Account')]
final class OpenApiDocumentController extends Controller
{
    public function __invoke(Request $request, Router $router): Response
    {
        $document = File::get(base_path('docs/openapi.json'));
        $hidden = array_values(array_filter(ApiContract::parts(), static fn (ApiDocumentPart $part): bool => ! $part->served()));
        $served = $this->served($document, $hidden, $this->routed($router));
        // The document changes with an addon switch: caches may keep it but must revalidate (ETag, 304) every time.
        $response = response($served, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'])->setEtag(hash('xxh128', $served));
        $response->isNotModified($request);

        return $response;
    }

    /** @return array<string, true> `<METHOD> <path>` of every /api route, the path as the document writes it (server `/api`) */
    private function routed(Router $router): array
    {
        $routed = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (str_starts_with($route->uri(), 'api/')) {
                foreach ($route->methods() as $method) {
                    $routed[$method.' '.substr($route->uri(), strlen('api'))] = true;
                }
            }
        }

        return $routed;
    }

    /**
     * Drops the operations without a route (an addon that is not installed) and every operation tagged by a switched-off
     * addon; then the tags and the schemas only those operations used, the ability lines that name only them, and the
     * switched-off addons' component properties and token notes.
     *
     * @param  list<ApiDocumentPart>  $hidden
     * @param  array<string, true>  $routed
     */
    private function served(string $json, array $hidden, array $routed): string
    {
        // Objects, not arrays, so empty JSON objects stay `{}`.
        /** @var object{paths: \stdClass, tags: list<object{name: string}>, components: object{schemas: \stdClass, securitySchemes: \stdClass}} $document */
        $document = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $hiddenTags = array_merge(...array_map(static fn (ApiDocumentPart $part): array => array_keys($part->tags()), $hidden));
        $schemas = $document->components->schemas;
        $usedBefore = $this->usedSchemas($document->paths, $schemas);
        $dropped = [];
        $keptTags = [];
        $pathItems = $document->paths;
        /** @var array<string, \stdClass> $paths */
        $paths = get_object_vars($pathItems);
        foreach ($paths as $path => $methods) {
            /** @var array<string, object{operationId: string, tags: list<string>}> $operations */
            $operations = get_object_vars($methods);
            foreach ($operations as $method => $operation) {
                if (! isset($routed[strtoupper($method).' '.$path]) || array_intersect($operation->tags, $hiddenTags) !== []) {
                    $dropped[] = $operation->operationId;
                    unset($methods->{$method});
                } else {
                    $keptTags = [...$keptTags, ...$operation->tags];
                }
            }
            if (get_object_vars($methods) === []) {
                unset($pathItems->{$path});
            }
        }
        if ($dropped === []) {
            return $json;
        }
        foreach (array_diff_key($usedBefore, $this->usedSchemas($pathItems, $schemas)) as $name => $unused) {
            unset($schemas->{$name});
        }
        foreach ($hidden as $part) {
            foreach ($part->componentProperties() as $component => $properties) {
                /** @var object{properties: \stdClass} $schema */
                $schema = $schemas->{$component};
                foreach (array_keys($properties) as $property) {
                    unset($schema->properties->{$property});
                }
            }
        }
        $notes = array_map(static fn (ApiDocumentPart $part): string => $part->tokenNote(), $hidden);
        /** @var \stdClass $bearer */
        $bearer = $document->components->securitySchemes->bearerAuth;
        /** @var string $description */
        $description = $bearer->description;
        // An ability line reads "- `<ability>`: <operationId>, <operationId>".
        $bearer->description = implode("\n", array_filter(explode("\n", $description), static fn (string $line): bool => ! in_array($line, $notes, true)
            && (preg_match('/^- `[^`]+`: (.+)$/', $line, $match) !== 1 || array_diff(explode(', ', $match[1]), $dropped) !== [])));
        $tags = array_values(array_filter($document->tags, static fn (object $tag): bool => in_array($tag->name, $keptTags, true)));

        return json_encode((object) [...get_object_vars($document), 'tags' => $tags], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return array<string, true> the schemas the paths reference, directly or through other schemas */
    private function usedSchemas(\stdClass $paths, \stdClass $schemas): array
    {
        $used = [];
        $pending = [$paths];
        while ($pending !== []) {
            preg_match_all('~"#/components/schemas/([^"]+)"~', json_encode(array_pop($pending), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $matches);
            foreach (array_diff(array_unique($matches[1]), array_keys($used)) as $name) {
                $used[$name] = true;
                $pending[] = $schemas->{$name};
            }
        }

        return $used;
    }
}

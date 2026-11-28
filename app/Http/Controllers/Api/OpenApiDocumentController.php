<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Contracts\ApiDocumentPart;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Support\OpenApi\ApiContract;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/** Serves the committed, drift-checked OpenAPI document; it describes the API and contains no monitoring data. */
#[Group('Account')]
final class OpenApiDocumentController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $document = File::get(base_path('docs/openapi.json'));
        $hidden = array_values(array_filter(ApiContract::parts(), static fn (ApiDocumentPart $part): bool => ! $part->served()));
        $served = $hidden === [] ? $document : $this->without($document, $hidden);
        // The document changes with an addon switch: caches may keep it but must revalidate (ETag, 304) every time.
        $response = response($served, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'])->setEtag(hash('xxh128', $served));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * A switched-off addon does not exist: the document drops its tags with their operations, its schemas and component
     * properties, its token note and the ability lines that name only its operations.
     *
     * @param  list<ApiDocumentPart>  $parts
     */
    private function without(string $json, array $parts): string
    {
        // Objects, not arrays, so empty JSON objects stay `{}`.
        /** @var object{paths: \stdClass, tags: list<object{name: string}>, components: object{schemas: \stdClass, securitySchemes: \stdClass}} $document */
        $document = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $tags = array_merge(...array_map(static fn (ApiDocumentPart $part): array => array_keys($part->tags()), $parts));
        $dropped = [];
        $pathItems = $document->paths;
        /** @var array<string, \stdClass> $paths */
        $paths = get_object_vars($pathItems);
        foreach ($paths as $path => $methods) {
            /** @var array<string, object{operationId: string, tags: list<string>}> $operations */
            $operations = get_object_vars($methods);
            foreach ($operations as $method => $operation) {
                if (array_intersect($operation->tags, $tags) !== []) {
                    $dropped[] = $operation->operationId;
                    unset($methods->{$method});
                }
            }
            if (get_object_vars($methods) === []) {
                unset($pathItems->{$path});
            }
        }
        $components = $document->components;
        $schemas = $components->schemas;
        foreach ($parts as $part) {
            foreach (array_keys($part->components()) as $name) {
                unset($schemas->{$name});
            }
            foreach ($part->componentProperties() as $component => $properties) {
                /** @var object{properties: \stdClass} $schema */
                $schema = $schemas->{$component};
                foreach (array_keys($properties) as $property) {
                    unset($schema->properties->{$property});
                }
            }
        }
        $notes = array_map(static fn (ApiDocumentPart $part): string => $part->tokenNote(), $parts);
        /** @var \stdClass $bearer */
        $bearer = $components->securitySchemes->bearerAuth;
        /** @var string $description */
        $description = $bearer->description;
        // An ability line reads "- `<ability>`: <operationId>, <operationId>".
        $bearer->description = implode("\n", array_filter(explode("\n", $description), static fn (string $line): bool => ! in_array($line, $notes, true)
            && (preg_match('/^- `[^`]+`: (.+)$/', $line, $match) !== 1 || array_diff(explode(', ', $match[1]), $dropped) !== [])));
        $kept = array_values(array_filter($document->tags, static fn (object $tag): bool => ! in_array($tag->name, $tags, true)));

        return json_encode((object) [...get_object_vars($document), 'tags' => $kept], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}

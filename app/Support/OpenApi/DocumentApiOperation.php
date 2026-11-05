<?php

declare(strict_types=1);

namespace Heptapod\Support\OpenApi;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

/**
 * Applies ApiContract to one /api operation before Scramble enforces "no unknown schema":
 * bearer security and `x-sanctum-abilities` from the route middleware, the real error envelopes,
 * explicit agent responses and the declared resolutions of unknown leaves.
 * Unknown leaves without a declaration stay unknown, so `scramble:export --fail-on-unknown` fails.
 */
final class DocumentApiOperation implements OperationTransformer
{
    /** @var array<string, true> leaf resolutions, component overrides and per-operation declarations applied in this process */
    public static array $applied = [];

    /** @var array<string, true> */
    private static array $walkedComponents = [];

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $route = $routeInfo->route;
        $routeName = $route->getName() ?? throw new \LogicException("OpenAPI: route {$route->uri()} needs a name.");
        // Scramble derives the operationId from the same route name without the `api.` group prefix.
        $name = str_starts_with($routeName, 'api.') ? substr($routeName, 4) : throw new \LogicException("OpenAPI: route {$routeName} is outside the api. name group.");
        $middleware = array_values(array_filter($route->gatherMiddleware(), is_string(...)));
        $protected = in_array('auth:sanctum', $middleware, true);
        if ($operation->summary === '') {
            // Readable name for renderers and linters; the operationId stays the contract.
            $operation->summary(ucfirst(strtolower(Str::headline(str_replace('.', ' ', $name)))));
        }
        $description = ApiContract::operationDescriptions()[$name] ?? null;
        if ($description !== null) {
            self::$applied['description:'.$name] = true;
            $operation->description($description);
        }
        if ($protected) {
            $operation->setExtensionProperty('sanctum-abilities', $this->abilities($middleware, $name));
        } else {
            $operation->security = [];
        }

        if (in_array($name, ApiContract::idempotent(), true)) {
            self::$applied['idempotent:'.$name] = true;
            $operation->addParameters([Parameter::make('Idempotency-Key', 'header')->setSchema(RawSchema::schema(['type' => 'string', 'pattern' => '^[\x21-\x7E]{1,128}$']))
                ->description('Optional. One stable key per intended write, reused on every retry of it. A repeat within 24 hours returns the stored response; '
                    .'the same key with a different request returns 409 idempotency_conflict.')]);
        }
        $parameterNames = array_map(static fn (Parameter|Reference $parameter): string => $parameter instanceof Parameter ? $parameter->name : '', $operation->parameters);
        if (in_array('per_page', $parameterNames, true)) {
            // PaginationRequest also accepts the bracket dialect; Scramble reads only the branch for scalar `page`.
            $mixed = ' Use either page/per_page or page[number]/page[size]; mixing them returns 422.';
            $operation->addParameters([
                Parameter::make('page[number]', 'query')->setSchema(RawSchema::schema(['type' => 'integer', 'minimum' => 1, 'default' => 1]))->description('Page number (bracket dialect).'.$mixed),
                Parameter::make('page[size]', 'query')->setSchema(RawSchema::schema(['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 200]))->description('Page size (bracket dialect).'.$mixed),
            ]);
        }

        $body = ApiContract::requestBodies()[$name] ?? null;
        if ($body !== null) {
            self::$applied['requestBodies:'.$name] = true;
            $operation->requestBodyObject = RequestBodyObject::make()->setContent('application/json', RawSchema::schema($body))->required();
        }

        $errorCodes = [];
        $responses = [];
        $explicit = ApiContract::responses()[$name] ?? null;
        if ($explicit !== null) {
            self::$applied['responses:'.$name] = true;
        }
        $problems = ApiContract::problems()[$name] ?? null;
        if ($problems !== null) {
            self::$applied['problems:'.$name] = true;
        }
        $problems ??= [];
        foreach ($operation->responses ?? [] as $response) {
            $resolved = $response instanceof Reference ? $response->resolve() : $response;
            $code = $resolved instanceof Response ? $resolved->code : throw new \LogicException("OpenAPI: {$name} has an unresolvable response.");
            if (! is_numeric($code) || (int) $code === 0) {
                // A runtime-status exception (ApiProblem); its statuses must be declared in ApiContract::problems().
                if ($problems === []) {
                    throw new \LogicException("OpenAPI: {$name} throws a runtime-status exception without declared problem codes.");
                }

                continue;
            }
            $code = (int) $code;
            if ($code >= 400) {
                $errorCodes[$code] = true;
            } elseif ($explicit === null) {
                $responses[] = $response;
            }
        }
        foreach ($explicit ?? [] as $code => $response) {
            $responses[] = new RawResponse($code, $response);
        }
        $statuses = array_keys($errorCodes + array_fill_keys(array_keys($problems), true) + ($protected ? [401 => true, 403 => true] : []) + [429 => true]);
        sort($statuses);
        foreach ($statuses as $status) {
            $responses[] = new RawResponse($status, $this->error($status, $problems[$status] ?? []));
        }
        $operation->responses = $responses;

        $this->walk($operation, ['', 'paths', $operation->path, $operation->method]);
    }

    /**
     * @param  list<string>  $middleware
     * @return array{all: list<string>, any: list<string>}
     */
    private function abilities(array $middleware, string $name): array
    {
        $abilities = ['all' => [], 'any' => []];
        foreach ($middleware as $entry) {
            foreach ([CheckAbilities::class => 'all', CheckForAnyAbility::class => 'any'] as $class => $kind) {
                if (str_starts_with($entry, $class.':')) {
                    $abilities[$kind] = [...$abilities[$kind], ...explode(',', substr($entry, strlen($class) + 1))];
                }
            }
        }
        if ($abilities['all'] === [] && $abilities['any'] === []) {
            throw new \LogicException("OpenAPI: protected operation {$name} has no Sanctum ability middleware.");
        }

        return $abilities;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, mixed>
     */
    private function error(int $status, array $codes): array
    {
        $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name];
        $problem = $codes === [] ? null : ['allOf' => [$ref('Problem'), ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'enum' => $codes]]]]];
        [$description, $schema] = match ($status) {
            401 => ['Missing, invalid, expired or session-only credentials, or a disabled user.', $ref('Unauthenticated')],
            403 => ['The token lacks a required ability, or the user\'s role or client access does not allow this action.', $ref('ErrorMessage')],
            404 => ['Not found or not visible to this token.', $ref('ErrorMessage')],
            422 => ['Invalid input.', $ref('ValidationError')],
            429 => ['Rate limited: 60 requests per minute per source IP before authentication, and 60 per minute per user and per token. See Retry-After.', $ref('TooManyRequests')],
            409, 410 => ['Domain error.', null],
            default => throw new \LogicException("OpenAPI: no error envelope for HTTP {$status}."),
        };
        $schema = match (true) {
            $schema === null => $problem ?? throw new \LogicException("OpenAPI: HTTP {$status} needs problem codes."),
            $problem === null => $schema,
            // anyOf: a Problem body also satisfies the looser `{error}` / `{message}` envelope.
            default => ['anyOf' => [$schema, $problem]],
        };
        $descriptions = ApiContract::problemDescriptions();
        foreach ($codes as $code) {
            $description .= "\n- `{$code}`: ".($descriptions[$code] ?? throw new \LogicException("OpenAPI: problem code {$code} needs a description."));
        }

        return ['description' => $description, ...($status === 429 ? ['headers' => ['Retry-After' => ['description' => 'Seconds to wait before retrying.', 'schema' => ['type' => 'string']]]] : []),
            'content' => ['application/json' => ['schema' => $schema]]];
    }

    /** @param list<int|string> $path Scramble's diagnostic pointer segments, so declarations can copy SE001 locations. */
    private function walk(object $node, array $path): void
    {
        foreach (get_object_vars($node) as $property => $value) {
            if (is_array($value)) {
                $changed = false;
                foreach ($value as $key => $item) {
                    if (is_object($item) && ($replacement = $this->visit($item, [...$path, $property, $key])) !== $item) {
                        $value[$key] = $replacement;
                        $changed = true;
                    }
                }
                if ($changed) {
                    new \ReflectionProperty($node, $property)->setValue($node, $value);
                }
            } elseif (is_object($value) && ($replacement = $this->visit($value, [...$path, $property])) !== $value) {
                new \ReflectionProperty($node, $property)->setValue($node, $replacement);
            }
        }
    }

    /** @param list<int|string> $path */
    private function visit(object $item, array $path): object
    {
        if (! str_starts_with($item::class, 'Dedoc\\Scramble\\Support\\Generator\\')) {
            return $item;
        }
        if ($item instanceof Type) {
            $pointer = implode('/', array_map(static fn (int|string $segment): string => str_replace(['~', '/'], ['~0', '~1'], addslashes((string) $segment)), $path));
            $declared = ApiContract::leafResolutions()[$pointer] ?? null;
            if ($declared !== null) {
                self::$applied[$pointer] = true;

                return new RawSchema($declared);
            }
        }
        // Scramble keeps `in:1` values as strings on an integer type; the request accepts the integer.
        if ($item instanceof IntegerType && $item->enum !== [] && array_all($item->enum, static fn (mixed $value): bool => is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            $item->enum(array_map(static fn (mixed $value): int => (int) (is_string($value) ? $value : 0), $item->enum));

            return $item;
        }
        if ($item instanceof UnknownType) {
            // Scramble types `in:` rules and enum-valued expressions as an unknown type that already carries its string values.
            if ($item->enum !== [] && array_all($item->enum, static fn (mixed $value): bool => is_string($value))) {
                return new StringType()->addProperties($item);
            }

            return $item;
        }
        if ($item instanceof Reference && $item->referenceType === 'schemas' && $item->uri === null) {
            $component = $item->getUniqueName();
            if (! is_string($component)) {
                throw new \LogicException('OpenAPI: schema reference without a name.');
            }
            if (! isset(self::$walkedComponents[$component])) {
                self::$walkedComponents[$component] = true;
                $schema = $item->resolve();
                if (! $schema instanceof Schema) {
                    throw new \LogicException("OpenAPI: component {$component} is not a schema.");
                }
                $override = ApiContract::componentOverrides()[$component] ?? null;
                if ($override !== null) {
                    self::$applied['component:'.$component] = true;
                    $schema->type = new RawSchema([...$override, 'title' => $component]);
                } else {
                    $this->walk($schema, ['', 'components', 'schemas', $component]);
                }
            }

            return $item;
        }
        $this->walk($item, $path);

        return $item;
    }
}

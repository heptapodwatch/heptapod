<?php

declare(strict_types=1);

namespace Heptapod\Support\OpenApi;

use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;

/**
 * Component schemas for laravel-data objects that API responses serialize with `toArray()`.
 * Scramble (free edition) cannot read laravel-data output, so this reads the public properties and the constructor PHPDoc types.
 * A property type it cannot map (an array without a `list<...>` or `array<string, ...>` PHPDoc type, a union, an
 * unknown class) throws, so a DTO change cannot silently publish an empty schema.
 */
final class DataObjectSchemas
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    /**
     * @param  list<class-string<Data>>  $roots
     * @return array<string, array<string, mixed>> component name => schema, including nested data objects and enums
     */
    public static function for(array $roots): array
    {
        $builder = new self;
        foreach ($roots as $root) {
            $builder->reference($root);
        }
        ksort($builder->schemas);

        return $builder->schemas;
    }

    /**
     * @param  class-string  $class
     * @return array{'$ref': string}
     */
    private function reference(string $class): array
    {
        $name = new \ReflectionClass($class)->getShortName();
        if (! array_key_exists($name, $this->schemas)) {
            $this->schemas[$name] = ['type' => 'object'];
            $this->schemas[$name] = is_subclass_of($class, \BackedEnum::class) ? $this->enum($class) : $this->object($class);
        }

        return ['$ref' => '#/components/schemas/'.$name];
    }

    /** @return array<string, mixed> */
    private function enum(string $class): array
    {
        /** @var class-string<\BackedEnum> $class */
        $values = array_map(static fn (\BackedEnum $case): int|string => $case->value, $class::cases());

        return ['type' => is_int($values[0] ?? null) ? 'integer' : 'string', 'enum' => $values];
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function object(string $class): array
    {
        if (! is_subclass_of($class, Data::class)) {
            throw new \LogicException("OpenAPI DTO schema: {$class} is not a laravel-data object.");
        }
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor() ?? throw new \LogicException("OpenAPI DTO schema: {$class} has no constructor.");
        $docTypes = $this->docParameterTypes((string) $constructor->getDocComment());
        $properties = [];
        // laravel-data serializes every public instance property, promoted or assigned in the constructor.
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getAttributes(Hidden::class) !== []) {
                continue;
            }
            $properties[$property->getName()] = $this->property($class, $property->getName(), $property->getType(), $docTypes[$property->getName()] ?? null);
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function property(string $class, string $property, ?\ReflectionType $type, ?string $docType): array
    {
        if (! $type instanceof \ReflectionNamedType) {
            throw new \LogicException("OpenAPI DTO schema: {$class}::\${$property} needs a single named type.");
        }
        $name = $type->getName();
        $schema = match (true) {
            $name === 'int' => ['type' => 'integer'],
            $name === 'string' => ['type' => 'string'],
            $name === 'bool' => ['type' => 'boolean'],
            $name === 'float' => ['type' => 'number'],
            is_a($name, \DateTimeInterface::class, true) => ['type' => 'string', 'format' => 'date-time'],
            $name === 'array' => $this->docArray($class, $property, $docType),
            enum_exists($name), is_subclass_of($name, Data::class) => $this->reference($name),
            default => throw new \LogicException("OpenAPI DTO schema: {$class}::\${$property} has unsupported type {$name}."),
        };

        return $type->allowsNull() ? $this->nullable($schema) : $schema;
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function docArray(string $class, string $property, ?string $docType): array
    {
        $docType = $docType === null ? null : preg_replace('/\|null$/', '', $docType);
        if ($docType !== null && preg_match('/^list<(.+)>$/', $docType, $list) === 1) {
            return ['type' => 'array', 'items' => $this->docScalar($class, $property, $list[1])];
        }
        if ($docType !== null && preg_match('/^array<string,\s*(.+)>$/', $docType, $map) === 1) {
            return ['type' => 'object', 'additionalProperties' => $this->docScalar($class, $property, $map[1])];
        }

        throw new \LogicException("OpenAPI DTO schema: {$class}::\${$property} needs a list<...> or array<string, ...> PHPDoc type.");
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function docScalar(string $class, string $property, string $docType): array
    {
        $nullable = str_ends_with($docType, '|null');
        $inner = $nullable ? substr($docType, 0, -5) : $docType;
        $schema = match ($inner) {
            'int' => ['type' => 'integer'],
            'string' => ['type' => 'string'],
            'bool' => ['type' => 'boolean'],
            default => $this->docClass($class, $property, $inner),
        };

        return $nullable ? $this->nullable($schema) : $schema;
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function docClass(string $class, string $property, string $shortName): array
    {
        $candidate = new \ReflectionClass($class)->getNamespaceName().'\\'.$shortName;
        if (enum_exists($candidate) || is_subclass_of($candidate, Data::class)) {
            return $this->reference($candidate);
        }

        throw new \LogicException("OpenAPI DTO schema: {$class}::\${$property} uses unsupported PHPDoc type {$shortName}.");
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function nullable(array $schema): array
    {
        if (isset($schema['$ref'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }
        if (is_string($schema['type'] ?? null)) {
            $schema['type'] = [$schema['type'], 'null'];

            return $schema;
        }

        throw new \LogicException('OpenAPI DTO schema: cannot make the schema nullable.');
    }

    /** @return array<string, string> */
    private function docParameterTypes(string $doc): array
    {
        preg_match_all('/@param\s+(\S+(?:,\s*\S+)?)\s+\$(\w+)/', $doc, $matches, PREG_SET_ORDER);
        $types = [];
        foreach ($matches as $match) {
            $types[$match[2]] = $match[1];
        }

        return $types;
    }
}

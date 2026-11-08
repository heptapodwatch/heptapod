<?php

declare(strict_types=1);

namespace Heptapod\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\Type;

/**
 * A JSON Schema written out by the app where Scramble cannot infer the runtime shape.
 * The schema is private so Scramble's schema checks treat it as a leaf.
 */
final class RawSchema extends Type
{
    /** @param array<string, mixed>|false $schema `false` is the JSON Schema that matches nothing (`additionalProperties: false`). */
    public function __construct(private readonly array|false $schema)
    {
        parent::__construct('raw');
    }

    /**
     * Scramble's factories declare no return type; this narrows them for callers.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function schema(array $schema): Schema
    {
        $wrapped = Schema::fromType(new self($schema));
        if (! $wrapped instanceof Schema) {
            throw new \LogicException('Scramble returned no schema.');
        }

        return $wrapped;
    }

    #[\Override]
    public function serializeAs31(): mixed
    {
        return $this->schema;
    }

    #[\Override]
    public function serializeAs32(): mixed
    {
        return $this->schema;
    }
}

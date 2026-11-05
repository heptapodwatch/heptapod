<?php

namespace Heptapod\Contracts;

use Illuminate\Contracts\Container\Container;

final class CheckRegistry
{
    /** @var array<string, class-string<CheckType>> */
    private array $types = [];

    /** @var array<string, OhDearImportMapping> */
    private array $importMappings = [];

    public function __construct(private readonly Container $container) {}

    public function importMapping(CheckKey $key): ?OhDearImportMapping
    {
        return $this->importMappings[$key->value] ?? null;
    }

    /** @param class-string<CheckType> $type */
    public function register(string $type, ?OhDearImportMapping $importMapping = null): void
    {
        /** @var CheckType $instance */
        $instance = $this->container->make($type);
        $key = $instance->definition()->key->value;
        if (isset($this->types[$key])) {
            throw new \LogicException("Check type already registered: {$key}");
        }
        $this->types[$key] = $type;
        if ($importMapping instanceof OhDearImportMapping) {
            $this->importMappings[$key] = $importMapping;
        }
    }

    public function get(CheckKey $key): CheckType
    {
        $type = $this->types[$key->value] ?? throw new \LogicException("Check addon is not installed: {$key->value}");

        /** @var CheckType $instance */
        $instance = $this->container->make($type);

        return $instance;
    }

    public function availability(CheckKey $key): CheckAvailability
    {
        if (! in_array($key, $this->keys(), true)) {
            return new CheckAvailability;
        }
        $type = $this->get($key);

        return $type instanceof ReportsAvailability ? $type->availability() : new CheckAvailability;
    }

    /** @return list<CheckKey> */
    public function unavailableKeys(): array
    {
        return array_values(array_filter($this->keys(), fn (CheckKey $key): bool => $this->availability($key)->reason !== null));
    }

    /** @return list<CheckKey> */
    public function keys(): array
    {
        return array_map(CheckKey::from(...), array_keys($this->types));
    }
}

<?php

namespace Heptapod\Contracts;

use Illuminate\Contracts\Container\Container;

final class CheckRegistry
{
    /** @var array<string, class-string<CheckType>> */
    private array $types = [];

    /** @var array<string, CheckDefinition> */
    private array $definitions = [];

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
        $definition = $instance->definition();
        $key = $definition->key->value;
        if (isset($this->types[$key])) {
            throw new \LogicException("Check type already registered: {$key}");
        }
        $this->types[$key] = $type;
        $this->definitions[$key] = $definition;
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

    /** A check type's static metadata, read once at registration: list pages ask for it per check. */
    public function definition(CheckKey $key): CheckDefinition
    {
        return $this->definitions[$key->value] ?? throw new \LogicException("Check addon is not installed: {$key->value}");
    }

    /**
     * The check type when it is installed and its class implements the contract. List pages ask per check, and
     * building every type through the container only to learn it does not implement the contract dominated them.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T|null
     */
    public function implementation(CheckKey $key, string $contract): ?object
    {
        if (! is_a($this->types[$key->value] ?? '', $contract, true)) {
            return null;
        }
        $type = $this->get($key);

        return $type instanceof $contract ? $type : null;
    }

    public function availability(CheckKey $key): CheckAvailability
    {
        return $this->implementation($key, ReportsAvailability::class)?->availability() ?? new CheckAvailability;
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

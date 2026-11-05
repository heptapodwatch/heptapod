<?php

declare(strict_types=1);

namespace Heptapod\Support\OpenApi;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\TaskDefinitionSchemaProvider;
use Heptapod\Enums\AlertSensitivity;
use Spatie\LaravelData\Optional;

/**
 * JSON Schemas for request bodies whose fields come from the installed addons' contract schemas
 * (check config schemas, the cron task-definition schema), so the document follows the addons, not a copy.
 * Every validation rule is mapped; an unknown rule kind throws, so a new rule cannot silently drop out of the document.
 *
 * @phpstan-type ObjectSchema array{type: 'object', properties: array<string, array<string, mixed>>, required: list<string>, additionalProperties: bool, allOf?: list<array<string, mixed>>, description?: string}
 */
final readonly class FieldSchemas
{
    /** Rules the server checks that JSON Schema cannot express; listed in `x-heptapod-server-rules`. */
    private const array ServerOnlyRules = ['timezone', 'heptapod_cron_expression', 'crawl_whitelist_url'];

    public function __construct(private CheckRegistry $registry) {}

    /**
     * One cron task definition as create and sync accept it.
     *
     * @return ObjectSchema
     */
    public function cronTaskDefinition(): array
    {
        foreach ($this->registry->keys() as $key) {
            $type = $this->registry->get($key);
            if ($type instanceof TaskDefinitionSchemaProvider) {
                return $this->object($type->taskDefinitionSchema()->fields, false, '');
            }
        }

        throw new \LogicException('OpenAPI: no installed check provides a task-definition schema.');
    }

    /**
     * A partial definition update: the server merges it with the stored task, then applies the full definition rules.
     *
     * @param  ObjectSchema  $definition
     * @return ObjectSchema
     */
    public static function partial(array $definition): array
    {
        unset($definition['allOf']);

        return [...$definition, 'required' => [],
            'description' => 'Partial update. The body is merged with the stored values; the merged definition must satisfy the create rules (required and conditional fields).'];
    }

    /** @return array<string, array<string, mixed>> `<check_key>_check_settings` => schema for every installed check */
    public function checkSettings(): array
    {
        $settings = [];
        foreach ($this->registry->keys() as $key) {
            $definition = $this->registry->get($key)->definition();
            $schema = self::partial($this->object($definition->configSchema->fields, false, ''));
            $schema['properties'] += [
                'failed_notification_threshold' => ['type' => $key === CheckKey::Uptime ? ['integer', 'null'] : 'integer',
                    'minimum' => AlertSensitivity::MinStoredMinutes, 'maximum' => AlertSensitivity::MaxStoredMinutes,
                    'description' => $key === CheckKey::Uptime
                        ? 'Failed minutes before an uptime alert. Null inherits the team default. Writing a number also sets recovery confirmation to 1 good check for values up to 2, otherwise 3. Incident opening thresholds are unchanged.'
                        : 'Failed checks before an incident opens.'],
                'frequency_value' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60],
                'frequency_unit' => ['type' => 'string', 'enum' => ['minute', 'hour', 'day']],
            ];
            // Schema::validate rejects unknown settings, so the object is closed after adding the scheduling keys.
            $schema['additionalProperties'] = false;
            $schema['description'] = 'Omitted settings keep their value; the merged settings must satisfy the check\'s rules. Secret values are write-only; `********` keeps the stored value.';
            $settings[$key->value.'_check_settings'] = $schema;
        }
        ksort($settings);

        return $settings;
    }

    /**
     * @param  array<string, Field>  $fields
     * @return ObjectSchema
     */
    private function object(array $fields, bool $additional, string $path): array
    {
        $properties = [];
        $required = [];
        $conditions = [];
        foreach ($fields as $name => $field) {
            $fieldPath = $path === '' ? $name : $path.'.'.$name;
            $properties[$name] = $this->field($field, $fieldPath);
            foreach ($field->rules as $rule) {
                [$kind, $arguments] = $this->parse($rule);
                if (in_array($kind, ['required', 'present'], true)) {
                    $required[] = $name;
                } elseif (in_array($kind, ['required_if', 'prohibited_unless', 'present_unless'], true)) {
                    $conditions[] = $this->condition($kind, $name, $arguments, $path, $rule);
                }
            }
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => array_values(array_unique($required)), 'additionalProperties' => $additional,
            ...($conditions === [] ? [] : ['allOf' => $conditions])];
    }

    /**
     * @param  list<string>  $arguments  [other field path, ...values]
     * @return array<string, mixed>
     */
    private function condition(string $kind, string $name, array $arguments, string $path, string $rule): array
    {
        $reference = array_shift($arguments) ?? throw new \LogicException("OpenAPI: rule {$rule} names no field.");
        // Schema::validateValues validates under a `values` root; task definitions validate at the root.
        $reference = str_starts_with($reference, 'values.') ? substr($reference, strlen('values.')) : $reference;
        $prefix = $path === '' ? '' : $path.'.';
        $sibling = str_starts_with($reference, $prefix) ? substr($reference, strlen($prefix)) : null;
        if ($sibling === null || $sibling === '' || str_contains($sibling, '.') || $arguments === []) {
            throw new \LogicException("OpenAPI: rule {$rule} on {$prefix}{$name} must reference a sibling field with values.");
        }
        $matches = ['required' => [$sibling], 'properties' => [$sibling => ['enum' => $arguments]]];

        return match ($kind) {
            // Laravel `required` also rejects null.
            'required_if' => ['if' => $matches, 'then' => ['required' => [$name], 'properties' => [$name => ['not' => ['type' => 'null']]]]],
            // Laravel `prohibited` accepts an absent or empty (null) value.
            'prohibited_unless' => ['if' => ['not' => $matches], 'then' => ['properties' => [$name => ['type' => 'null']]]],
            'present_unless' => ['if' => ['not' => $matches], 'then' => ['required' => [$name]]],
            default => throw new \LogicException("OpenAPI: no condition mapping for {$rule}."),
        };
    }

    /** @return array<string, mixed> */
    private function field(Field $field, string $path): array
    {
        $schema = match ($field->type) {
            FieldType::String => ['type' => 'string'],
            FieldType::Secret => ['type' => 'string', 'writeOnly' => true, 'description' => 'Secret; responses return `********` or null.'],
            FieldType::Integer => ['type' => 'integer'],
            FieldType::Number => ['type' => 'number'],
            FieldType::Boolean => ['type' => 'boolean'],
            FieldType::Object => $this->object($field->properties, $field->additionalProperties, $path),
            FieldType::List => ['type' => 'array', 'items' => $field->items instanceof Field ? $this->field($field->items, $path.'.*') : ['type' => 'string'],
                ...($field->items instanceof Field && array_any($field->items->rules, static fn (string $rule): bool => str_starts_with($rule, 'distinct')) ? ['uniqueItems' => true] : [])],
        };
        $nullable = false;
        $enum = null;
        $serverRules = [];
        foreach ($field->rules as $rule) {
            [$kind, $arguments] = $this->parse($rule);
            match ($kind) {
                // Object-level rules, mapped by object().
                'required', 'present', 'required_if', 'prohibited_unless', 'present_unless', 'sometimes' => null,
                'nullable' => $nullable = true,
                'in' => $enum = $arguments,
                'min', 'max' => $schema[$this->bound($field->type, $kind)] = $this->number($arguments[0] ?? null, $rule),
                'between' => $schema = [...$schema, $this->bound($field->type, 'min') => $this->number($arguments[0] ?? null, $rule), $this->bound($field->type, 'max') => $this->number($arguments[1] ?? null, $rule)],
                'regex' => $schema['pattern'] = $this->pattern($rule),
                'not_regex' => $schema['not'] = ['pattern' => $this->pattern($rule)],
                'url' => $schema['format'] = 'uri',
                'json' => $schema['contentMediaType'] = 'application/json',
                // Laravel `list.*` distinct makes the items unique; the list branch sets uniqueItems on the parent.
                'distinct' => str_ends_with($path, '.*') ? null : throw new \LogicException("OpenAPI: {$rule} outside list items at {$path}."),
                default => in_array($kind, self::ServerOnlyRules, true) ? $serverRules[] = $kind
                    : throw new \LogicException("OpenAPI: unknown validation rule kind `{$kind}` on {$path}; map it in FieldSchemas."),
            };
        }
        if ($serverRules !== []) {
            $schema['x-heptapod-server-rules'] = $serverRules;
        }
        if (! $field->default instanceof Optional) {
            $schema['default'] = $field->default;
        }
        if ($nullable) {
            $schema['type'] = [$schema['type'], 'null'];
        }
        if ($enum !== null) {
            $schema['enum'] = $nullable ? [...$enum, null] : $enum;
        }

        return $schema;
    }

    /** @return array{string, list<string>} */
    private function parse(mixed $rule): array
    {
        if (! is_string($rule)) {
            throw new \LogicException('OpenAPI: only string validation rules can be documented, got '.get_debug_type($rule).'.');
        }
        $parts = explode(':', $rule, 2);

        return [$parts[0], isset($parts[1]) && ! in_array($parts[0], ['regex', 'not_regex'], true) ? explode(',', $parts[1]) : []];
    }

    private function bound(FieldType $type, string $kind): string
    {
        return match ($type) {
            FieldType::String, FieldType::Secret => $kind === 'min' ? 'minLength' : 'maxLength',
            FieldType::List, FieldType::Object => $kind === 'min' ? 'minItems' : 'maxItems',
            default => $kind === 'min' ? 'minimum' : 'maximum',
        };
    }

    private function number(?string $value, string $rule): int
    {
        if ($value === null || preg_match('/^\d+$/', $value) !== 1) {
            throw new \LogicException("OpenAPI: cannot map bound in {$rule}.");
        }

        return (int) $value;
    }

    /** Converts a PHP regex rule to a JSON Schema pattern; flags without an equivalent throw. */
    private function pattern(string $rule): string
    {
        $expression = explode(':', $rule, 2)[1] ?? '';
        $delimiter = $expression[0] ?? '';
        $end = strrpos($expression, $delimiter);
        if ($delimiter === '' || $end === false || $end === 0) {
            throw new \LogicException("OpenAPI: cannot parse {$rule}.");
        }
        $pattern = substr($expression, 1, $end - 1);
        $flags = substr($expression, $end + 1);
        foreach (str_split($flags) as $flag) {
            if (in_array($flag, ['D', 'u', ''], true)) {
                continue; // JSON Schema `$` without the m flag already matches only at the end; patterns are Unicode.
            }
            if ($flag === 'i' && preg_match('/^[\^$a-zA-Z0-9-]*$/', $pattern) === 1) {
                $pattern = preg_replace_callback('/[a-zA-Z]/', static fn (array $letter): string => '['.strtolower($letter[0]).strtoupper($letter[0]).']', $pattern) ?? throw new \LogicException('regex');

                continue;
            }
            throw new \LogicException("OpenAPI: regex flag `{$flag}` in {$rule} has no JSON Schema equivalent.");
        }
        if ($delimiter !== '/') {
            return str_replace('\\'.$delimiter, $delimiter, $pattern);
        }

        return $pattern;
    }
}

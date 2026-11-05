<?php

namespace Heptapod\Contracts\Schema;

use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class Field extends Data
{
    /** @param list<string> $rules
     * @param  array<string, Field>  $properties
     */
    public function __construct(
        public readonly FieldType $type,
        public readonly array $rules = [],
        public readonly array $properties = [],
        public readonly ?Field $items = null,
        public readonly ?FieldPresentation $presentation = null,
        public readonly bool $additionalProperties = false,
        public readonly bool $targetUrl = false,
        /** @var array<array-key, mixed>|string|int|float|bool|null|Optional */
        public readonly array|string|int|float|bool|null|Optional $default = new Optional,
        public readonly bool $uppercase = false,
        public readonly bool $trimmedStrings = false,
    ) {
        if ($trimmedStrings && ($type !== FieldType::List || $items?->type !== FieldType::String)) {
            throw new \InvalidArgumentException('Only lists of non-secret strings may trim and drop blank items.');
        }
        if ($uppercase && $type !== FieldType::String) {
            throw new \InvalidArgumentException('Only non-secret string fields may normalize case.');
        }
        if (($type === FieldType::List) !== ($items instanceof Field)) {
            throw new \InvalidArgumentException('Only list fields must have an item schema.');
        }
        if ($properties !== [] && $type !== FieldType::Object) {
            throw new \InvalidArgumentException('Only object fields have properties.');
        }
    }

    public function normalize(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($this->uppercase && is_string($value)) {
            return strtoupper($value);
        }
        if ($this->trimmedStrings && is_array($value) && array_is_list($value)) {
            $value = array_values(array_filter(
                array_map(static fn (mixed $item): mixed => is_string($item) ? trim($item) : $item, $value),
                static fn (mixed $item): bool => $item !== '',
            ));
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $field = $this->type === FieldType::List ? $this->items : ($this->properties[$key] ?? null);
                if ($field !== null) {
                    $value[$key] = $field->normalize($child);
                }
            }
        }

        return $value;
    }

    /** @return array<string, list<string|\Closure|ValidationRule>> */
    public function validationRules(string $path): array
    {
        $typeRules = match ($this->type) {
            FieldType::Object => [$this->additionalProperties ? 'array' : 'array:'.implode(',', array_keys($this->properties)),
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_array($value) && array_filter(array_keys($value), is_int(...)) !== []) {
                        $fail(__('validation.array', ['attribute' => $attribute]));
                    }
                },
            ],
            FieldType::List => ['array', 'list'],
            FieldType::Secret => ['string', new SecretMinimum],
            FieldType::Integer => ['integer:strict'],
            FieldType::Boolean => ['boolean:strict'],
            FieldType::Number => ['numeric:strict'],
            default => [$this->type->value],
        };
        if ($this->targetUrl) {
            $typeRules[] = 'url:http,https';
            $typeRules[] = static function (string $attribute, mixed $value, \Closure $fail): void {
                $parts = is_string($value) ? parse_url($value) : false;
                if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
                    $fail(__('api.url_credentials_unsupported'));
                }
            };
        }
        if (in_array($this->type, [FieldType::Integer, FieldType::Number, FieldType::Boolean], true) && ! in_array('nullable', $this->rules, true)) {
            array_unshift($typeRules, 'filled');
        }
        $rules = [$path => [...$this->rules, ...$typeRules]];
        foreach ($this->properties as $name => $field) {
            $rules += $field->validationRules($path.'.'.$name);
        }
        if ($this->items instanceof Field) {
            $rules += $this->items->validationRules($path.'.*');
        }

        return $rules;
    }

    /**
     * Validation messages name a field by its translated label, never by its path ("The values.email field …").
     *
     * @return array<string, string>
     */
    public function attributeNames(string $path): array
    {
        $names = $this->presentation instanceof FieldPresentation ? [$path => (string) __($this->presentation->labelKey)] : [];
        foreach ($this->properties as $name => $field) {
            $names += $field->attributeNames($path.'.'.$name);
        }
        if ($this->items instanceof Field) {
            $names += $this->items->attributeNames($path.'.*');
        }

        return $names;
    }

    /**
     * Explicitly empty optional secrets clear a value; empty credential headers remove the whole header.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function withoutEmptySecrets(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $field = $this->type === FieldType::List ? $this->items : ($this->properties[$key] ?? null);
            if ($field === null) {
                $result[$key] = $value;

                continue;
            }
            if ($field->type === FieldType::Secret && $value === '' && ! in_array('required', $field->rules, true)) {
                continue;
            }
            if ($this->type === FieldType::List && is_array($value) && isset($value['name']) && ($value['value'] ?? null) === '' && ($field->properties['value'] ?? null)?->type === FieldType::Secret) {
                continue;
            }
            $result[$key] = is_array($value) ? $field->withoutEmptySecrets($value) : $value;
        }

        return $this->type === FieldType::List && array_is_list($values) ? array_values($result) : $result;
    }

    /** @return array<string, string> Configured target URLs keyed by their schema path. */
    public function targetUrls(mixed $value, string $path = ''): array
    {
        if ($this->targetUrl && is_string($value)) {
            return [$path => $value];
        }
        if (! is_array($value)) {
            return [];
        }
        $urls = [];
        foreach ($value as $key => $child) {
            $field = $this->type === FieldType::List ? $this->items : ($this->properties[$key] ?? null);
            if ($field !== null) {
                $urls += $field->targetUrls($child, $path === '' ? (string) $key : $path.'.'.$key);
            }
        }

        return $urls;
    }

    /** @return array{mixed, mixed, bool} Split by schema, retaining ordered lists and duplicate header names. */
    public function split(mixed $value): array
    {
        if ($this->type === FieldType::Secret) {
            return [null, $value, true];
        }
        if ($value === null || ! in_array($this->type, [FieldType::Object, FieldType::List], true)) {
            return [$value, null, false];
        }
        if (! is_array($value)) {
            throw new \InvalidArgumentException('Nested settings must pass schema validation before splitting.');
        }
        $public = $value;
        $secrets = [];
        foreach ($value as $key => $child) {
            $field = $this->type === FieldType::List ? $this->items : ($this->properties[$key] ?? null);
            if ($field === null) {
                continue; // Explicitly permitted additional properties, e.g. Lighthouse notifications.
            }
            [$visible, $secret, $hasSecret] = $field->split($child);
            $public[$key] = $visible;
            if ($hasSecret) {
                $secrets[$key] = $secret;
            }
        }

        return [$this->type === FieldType::Object ? (object) $public : $public, $secrets, $secrets !== []];
    }
}

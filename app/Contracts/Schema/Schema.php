<?php

namespace Heptapod\Contracts\Schema;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class Schema extends Data
{
    /** @param array<string, Field> $fields */
    public function __construct(public readonly array $fields) {}

    /** @return array<string, mixed> Explicit runtime/import defaults, not partial-request resets. */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->fields as $name => $field) {
            if (! $field->default instanceof Optional) {
                $defaults[$name] = $field->default;
            }
        }

        return $defaults;
    }

    /** @return array<string, list<string|\Closure|ValidationRule>> */
    public function validationRules(): array
    {
        $rules = [];
        foreach ($this->fields as $name => $field) {
            $rules += $field->validationRules($name);
        }

        return $rules;
    }

    /**
     * Unknown root fields are rejected, not silently dropped. No implicit defaults or omission resets.
     *
     * @param  array<string, mixed>  $values
     */
    public function validateConfig(#[\SensitiveParameter] array $values): ValidatedConfig
    {
        $root = new Field(FieldType::Object, properties: $this->fields);
        /** @var array<string, mixed> $values */
        $values = $root->withoutEmptySecrets($values);
        $validated = $this->validateValues($values);
        /** @var array{\stdClass, array<string, mixed>, bool} $split */
        $split = $root->split($validated);

        /** @var array<string, mixed> $public */
        $public = (array) $split[0];

        return new ValidatedConfig($public, $split[1]);
    }

    /**
     * Recorder validates the metrics DTO with metricSchema; a mismatch becomes an errored run.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function validateValues(#[\SensitiveParameter] array $values): array
    {
        $root = new Field(FieldType::Object, properties: $this->fields);
        foreach ($this->fields as $name => $field) {
            if (array_key_exists($name, $values)) {
                $values[$name] = $field->normalize($values[$name]);
            }
        }
        /** @var array<string, mixed> $validated */
        $validated = Validator::make(['values' => $values], $root->validationRules('values'), attributes: $root->attributeNames('values'))->validate()['values'];

        return $validated;
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Illuminate\Validation\ValidationException;

final class SecretMerge
{
    public static function preserve(Field $field, mixed $value, mixed $previous, string $path): mixed
    {
        if ($field->type === FieldType::Secret && ($value === '********' || $value === null)) {
            if ($previous === null && $value === '********') {
                $attribute = $field->presentation instanceof FieldPresentation ? __($field->presentation->labelKey) : $path;
                throw ValidationException::withMessages([$path => __('validation.required', ['attribute' => $attribute])]);
            }

            return $previous;
        }
        if ($value === null && ($field->items?->properties['value'] ?? null)?->type === FieldType::Secret) {
            return $previous;
        }
        if (! is_array($value)) {
            return $value;
        }
        $previous = is_array($previous) ? $previous : [];
        // Ordered name/value lists retain a secret only for the same header at the same position.
        if (isset($value['name']) && ($previous['name'] ?? null) !== $value['name']) {
            $previous = [];
        }
        foreach ($field->properties as $name => $child) {
            if (array_key_exists($name, $value)) {
                $value[$name] = self::preserve($child, $value[$name], $previous[$name] ?? null, $path.'.'.$name);
            } elseif ($child->type === FieldType::Secret && array_key_exists($name, $previous)) {
                $value[$name] = $previous[$name];
            }
        }
        if ($field->items instanceof Field) {
            // A unique header name identifies a row even when an earlier row was removed.
            $uniqueNames = in_array('distinct:ignore_case', $field->items->properties['name']->rules ?? [], true);
            $previousByName = $uniqueNames ? array_column($previous, null, 'name') : [];
            foreach ($value as $index => $item) {
                $name = is_array($item) ? $item['name'] ?? null : null;
                $oldItem = $uniqueNames ? (is_string($name) ? $previousByName[$name] ?? null : null) : ($previous[$index] ?? null);
                $value[$index] = self::preserve($field->items, $item, $oldItem, $path.'.'.$index);
            }
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Illuminate\Validation\ValidationException;

final class SecretMerge
{
    public static function preserve(Field $field, mixed $value, mixed $previous, string $path, bool $nullClearsNullable = false): mixed
    {
        if ($field->type === FieldType::Secret && ($value === '********' || ($value === null && (! $nullClearsNullable || ! in_array('nullable', $field->rules, true))))) {
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
        if (isset($value['name']) && self::nameKey($field, $previous['name'] ?? null) !== self::nameKey($field, $value['name'])) {
            $previous = [];
        }
        foreach ($field->properties as $name => $child) {
            if (array_key_exists($name, $value)) {
                $value[$name] = self::preserve($child, $value[$name], $previous[$name] ?? null, $path.'.'.$name, $nullClearsNullable);
            } elseif ($child->type === FieldType::Secret && array_key_exists($name, $previous)) {
                $value[$name] = $previous[$name];
            }
        }
        if ($field->items instanceof Field) {
            if (isset($field->items->properties['name'])) {
                $caseInsensitive = self::hasCaseInsensitiveNames($field->items);
                $previousGroups = [];
                foreach ($previous as $item) {
                    if (is_array($item) && is_string($item['name'] ?? null)) {
                        $key = $caseInsensitive ? strtolower($item['name']) : $item['name'];
                        $previousGroups[$key][] = $item;
                    }
                }
                $incomingCounts = [];
                foreach ($value as $item) {
                    if (is_array($item) && is_string($item['name'] ?? null)) {
                        $key = $caseInsensitive ? strtolower($item['name']) : $item['name'];
                        $incomingCounts[$key] = ($incomingCounts[$key] ?? 0) + 1;
                    }
                }
                $seen = [];
                foreach ($value as $index => $item) {
                    $oldItem = null;
                    if (is_array($item) && is_string($item['name'] ?? null)) {
                        $key = $caseInsensitive ? strtolower($item['name']) : $item['name'];
                        $occurrence = $seen[$key] ?? 0;
                        $seen[$key] = $occurrence + 1;
                        $group = $previousGroups[$key] ?? [];
                        // Match uniquely named rows across removals/reordering, and duplicate-name rows only when their count is unchanged.
                        if ($group !== [] && ($incomingCounts[$key] ?? 0) === count($group)) {
                            $oldItem = $group[$occurrence];
                        }
                    }
                    $value[$index] = self::preserve($field->items, $item, $oldItem, $path.'.'.$index, $nullClearsNullable);
                }
            } else {
                foreach ($value as $index => $item) {
                    $value[$index] = self::preserve($field->items, $item, $previous[$index] ?? null, $path.'.'.$index, $nullClearsNullable);
                }
            }
        }

        return $value;
    }

    private static function nameKey(Field $field, mixed $name): mixed
    {
        return is_string($name) && self::hasCaseInsensitiveNames($field) ? strtolower($name) : $name;
    }

    private static function hasCaseInsensitiveNames(Field $field): bool
    {
        return in_array('distinct:ignore_case', $field->properties['name']->rules ?? [], true);
    }
}

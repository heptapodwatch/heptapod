<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Illuminate\Validation\ValidationException;

final class SecretMerge
{
    public static function preserve(Field $field, mixed $value, mixed $previous, string $path, bool $nullClearsNullable = false, bool $inList = false): mixed
    {
        if ($field->type === FieldType::Secret && ($value === '********' || ($value === null && (! $nullClearsNullable || ! in_array('nullable', $field->rules, true))))) {
            if ($previous === null && $value === '********') {
                $message = $inList
                    ? __('validation.secret_reentry_rows_changed')
                    : __('validation.required', ['attribute' => $field->presentation instanceof FieldPresentation ? __($field->presentation->labelKey) : $path]);
                throw ValidationException::withMessages([$path => $message]);
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
                $value[$name] = self::preserve($child, $value[$name], $previous[$name] ?? null, $path.'.'.$name, $nullClearsNullable, $inList);
            } elseif ($child->type === FieldType::Secret && array_key_exists($name, $previous)) {
                $value[$name] = $previous[$name];
            }
        }
        if ($field->items instanceof Field) {
            // Case-insensitively unique names identify rows across reordering; other lists accept masks only when the name sequence is unchanged and every duplicate-name group is all-masked.
            $uniqueNames = self::hasCaseInsensitiveNames($field->items);
            $previousByName = $uniqueNames ? array_change_key_case(array_column($previous, null, 'name')) : [];
            $allowPositionalMasks = true;
            if (! $uniqueNames && isset($field->items->properties['name'])) {
                $previousNames = self::rowNames($previous);
                $incomingNames = self::rowNames($value);
                $allowPositionalMasks = $previousNames !== null && $previousNames === $incomingNames;
                if ($allowPositionalMasks) {
                    $counts = array_count_values($incomingNames);
                    foreach ($value as $index => $item) {
                        if ($counts[$incomingNames[$index]] > 1 && (! is_array($item) || ($item['value'] ?? null) !== '********')) {
                            $allowPositionalMasks = false;
                            break;
                        }
                    }
                }
            }
            foreach ($value as $index => $item) {
                $name = is_array($item) ? $item['name'] ?? null : null;
                $oldItem = $uniqueNames
                    ? (is_string($name) ? $previousByName[strtolower($name)] ?? null : null)
                    : ($allowPositionalMasks ? ($previous[$index] ?? null) : null);
                $value[$index] = self::preserve($field->items, $item, $oldItem, $path.'.'.$index, $nullClearsNullable, inList: true);
            }
        }

        return $value;
    }

    /** @param array<array-key, mixed> $rows
     * @return list<string>|null
     */
    private static function rowNames(array $rows): ?array
    {
        if (! array_is_list($rows)) {
            return null;
        }
        $names = [];
        foreach ($rows as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                return null;
            }
            $names[] = $item['name'];
        }

        return $names;
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

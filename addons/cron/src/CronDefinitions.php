<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Validator;

final class CronDefinitions
{
    public static function schema(): Schema
    {
        return new Schema([
            'name' => new Field(FieldType::String, ['required', 'max:255']),
            'type' => new Field(FieldType::String, ['required', 'in:simple,cron']),
            'frequency_in_minutes' => new Field(FieldType::Integer, ['required_if:type,simple', 'prohibited_unless:type,simple', 'nullable', 'min:1', 'max:2147483647']),
            'grace_time_in_minutes' => new Field(FieldType::Integer, ['sometimes', 'nullable', 'min:0', 'max:2147483647']),
            'cron_expression' => new Field(FieldType::String, ['required_if:type,cron', 'prohibited_unless:type,cron', 'nullable', 'max:255', 'heptapod_cron_expression']),
            'server_timezone' => new Field(FieldType::String, ['required_if:type,cron', 'nullable', 'timezone:all']),
            'description' => new Field(FieldType::String, ['sometimes', 'nullable', 'max:10000']),
        ]);
    }

    /** @param array<string, mixed> $values */
    public function create(int $checkId, array $values): CronCheck
    {
        return new Actions\CreateCronTask($this, new CronDefinitionRevision)($checkId, $values);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function validate(array $values): array
    {
        $schema = self::schema();
        Validator::make(['definition' => $values], [
            'definition' => ['required', 'array:'.implode(',', array_keys($schema->fields))],
        ])->validate();
        /** @var array<string, mixed> $validated */
        $validated = Validator::make($values, $schema->validationRules())->validate();

        return $validated;
    }
}

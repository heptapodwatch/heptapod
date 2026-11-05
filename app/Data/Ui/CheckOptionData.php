<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\ReportsAvailability;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\TaskDefinitionSchemaProvider;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckOptionData extends Data
{
    public static function fromType(CheckType $type, ?bool $recommended = null): self
    {
        $definition = $type->definition();

        $reason = $type instanceof ReportsAvailability ? $type->availability()->reason : null;

        return new self($definition->key, $definition->labelKey, $definition->icon, $reason === null && ($recommended ?? $definition->recommended), $definition->requiresHttps, $definition->defaultInterval, $definition->configSchema,
            $type instanceof TaskDefinitionSchemaProvider ? $type->taskDefinitionSchema() : null, $reason);
    }

    public function __construct(
        public readonly CheckKey $key,
        public readonly string $label_key,
        public readonly string $icon,
        public readonly bool $recommended,
        public readonly bool $requires_https,
        public readonly int $interval_seconds,
        public readonly Schema $config_schema,
        public readonly ?Schema $task_definition_schema = null,
        public readonly ?string $unavailable_reason = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SuppressionSubject extends Data
{
    public function __construct(
        public readonly int $checkId,
        public readonly ?SuppressionComponent $componentType = null,
        public readonly ?int $componentId = null,
    ) {
        if ($checkId < 1 || (! $componentType instanceof SuppressionComponent) !== ($componentId === null) || ($componentId !== null && $componentId < 1)) {
            throw new \InvalidArgumentException('Suppression requires a check and either no component or a typed positive component id.');
        }
    }
}

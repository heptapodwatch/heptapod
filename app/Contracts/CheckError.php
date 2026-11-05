<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Engine exception/timeout diagnostics; redact credential content before constructing. */
#[TypeScript]
final class CheckError extends Data
{
    public function __construct(public readonly string $class, public readonly string $message) {}
}

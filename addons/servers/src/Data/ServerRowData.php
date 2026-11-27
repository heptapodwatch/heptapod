<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Heptapod\Addons\Servers\ServerState;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A server with its state and newest values, as the list and the server page show it. */
#[TypeScript]
final class ServerRowData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ServerState $state,
        public readonly ?string $last_report_at,
        public readonly ?string $agent_version,
        public readonly ?ServerValuesData $values,
    ) {}
}

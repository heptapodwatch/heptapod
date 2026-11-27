<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Heptapod\Addons\Servers\ServerIssueKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** An open server issue as the server page and the list show it; resolved ones live in Activity. */
#[TypeScript]
final class ServerIssueData extends Data
{
    /** @param array<string, string|int|float> $values the value, threshold and duration it opened on */
    public function __construct(
        public readonly int $id,
        public readonly ServerIssueKind $kind,
        public readonly string $scope,
        public readonly string $opened_at,
        public readonly array $values,
    ) {}
}

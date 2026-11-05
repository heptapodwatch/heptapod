<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DnsMetrics extends Data
{
    public function __construct(public readonly int $record_count, public readonly int $issue_count, public readonly bool $changed) {}
}

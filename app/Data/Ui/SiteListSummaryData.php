<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SiteListSummaryData extends Data
{
    /** @param int $clients Clients with at least one visible site. */
    public function __construct(public readonly int $total, public readonly int $attention, public readonly int $paused, public readonly int $clients) {}
}

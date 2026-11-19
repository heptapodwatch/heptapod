<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One loaded batch of the Activity timeline; `data` is the path Inertia's infinite scroll merges on. */
#[TypeScript]
final class ActivityBatchData extends Data
{
    /** @param list<ActivityGroupData> $data */
    public function __construct(public readonly array $data) {}
}

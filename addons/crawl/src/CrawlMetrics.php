<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CrawlMetrics extends Data
{
    public function __construct(public int $total, public int $internal, public int $external, public int $broken_internal, public int $broken_external, public int $mixed_content) {}

    public static function schema(): Schema
    {
        return new Schema(array_fill_keys(['total', 'internal', 'external', 'broken_internal', 'broken_external', 'mixed_content'], new Field(FieldType::Integer, ['required', 'min:0'])));
    }
}

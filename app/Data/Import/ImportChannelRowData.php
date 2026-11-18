<?php

declare(strict_types=1);

namespace Heptapod\Data\Import;

use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\ImportOperation;
use Heptapod\Enums\NotificationCategory;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Only the routing preview crosses the import boundary; destination configuration never does. */
#[TypeScript]
final class ImportChannelRowData extends Data
{
    /**
     * @param  list<string>  $tags
     * @param  list<NotificationCategory>  $categories
     * @param  list<CheckKey>  $check_types
     * @param  list<string>  $notices
     */
    public function __construct(
        public readonly int $source_id,
        public readonly string $kind_label,
        public readonly string $level,
        public readonly array $tags,
        public ImportOperation $operation,
        public readonly bool $enabled,
        public readonly array $categories = [],
        public readonly array $check_types = [],
        public array $notices = [],
        public ?string $client_name = null,
    ) {}
}

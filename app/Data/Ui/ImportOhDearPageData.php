<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\ImportSection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ImportOhDearPageData extends Data
{
    /**
     * @param  list<ImportSection>  $sections
     * @param  list<ClientData>  $clients
     * @param  list<string>  $timezones  Server IANA list for the inline client-create sheet.
     */
    public function __construct(
        public readonly array $clients,
        public readonly array $timezones,
        public readonly array $sections,
        public readonly int $plan_page_size,
    ) {}
}

<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One read of a server-searched picker: the bounded matches, and whether more match than were returned. */
#[TypeScript]
final class PickerResultsData extends Data
{
    /** @param list<PickerSiteData>|list<ClientData> $options */
    public function __construct(
        #[LiteralTypeScriptType('Heptapod.Data.Ui.PickerSiteData[] | Heptapod.Data.Ui.ClientData[]')]
        public readonly array $options,
        public readonly bool $more,
    ) {}
}

<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AddSitePageData extends Data
{
    /**
     * @param  list<ClientData>  $clients
     * @param  list<CheckOptionData>  $checks
     * @param  list<string>  $timezones  Server IANA list for the inline client-create sheet.
     */
    public function __construct(
        public readonly array $clients,
        public readonly array $checks,
        public readonly bool $can_create_site,
        public readonly ?int $selected_client_id = null,
        public readonly array $timezones = [],
    ) {}
}

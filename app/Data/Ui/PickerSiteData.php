<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\SiteIconBacking;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A site as a server-searched picker lists it: identifiable when names collide (avatar, client, host). */
#[TypeScript]
final class PickerSiteData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $host,
        public readonly int $client_id,
        /** "No client" for a site without a client. */
        public readonly string $client_name,
        public readonly bool $client_is_internal,
        /** The zone of the site's client, for pickers that follow it (maintenance). */
        public readonly string $timezone,
        public readonly ?string $icon_url,
        public readonly SiteIconBacking $icon_backing,
        /** Client · monitored address without scheme: the same for this site in every answer, and unique to it. */
        public readonly string $detail,
    ) {}
}

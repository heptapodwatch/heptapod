<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ClientSettingsPageData extends Data
{
    /** @param list<ClientMemberData> $members
     * @param  list<ClientAvatarSiteData>  $avatar_sites
     * @param  list<ClientData>  $clients
     * @param  list<string>  $timezones  Server IANA list (DateTimeZone::listIdentifiers), plus the stored zone when missing.
     */
    public function __construct(public readonly ?ClientData $client, public readonly array $members, public readonly array $avatar_sites = [], public readonly array $timezones = [], public readonly array $clients = [], public readonly int $site_count = 0, public readonly int $archive_paused_site_count = 0) {}
}

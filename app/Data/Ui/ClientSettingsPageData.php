<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ClientSettingsPageData extends Data
{
    /** @param list<ClientMemberData> $members
     * @param  bool  $has_icon_sites  whether a site of the client has an icon to use as the avatar
     * @param  int|null  $internal_client_id  the archive dialog's default move target (No client), when the user can see it
     * @param  list<string>  $timezones  Server IANA list (DateTimeZone::listIdentifiers), plus the stored zone when missing.
     */
    public function __construct(public readonly ?ClientData $client, public readonly array $members, public readonly bool $has_icon_sites = false, public readonly array $timezones = [], public readonly ?int $internal_client_id = null, public readonly int $site_count = 0, public readonly int $archive_paused_site_count = 0) {}
}

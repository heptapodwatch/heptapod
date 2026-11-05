<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\RecipientKind;
use Heptapod\Enums\SiteIconBacking;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * RecipientSettings for one recipient: the team, the signed-in user or a client (design §7.1). The grid lists only the
 * categories this recipient may see, each with its allowed modes; a channel's system_locked keeps its System cell on.
 */
#[TypeScript]
final class NotificationSettingsPageData extends Data
{
    /**
     * @param  list<NotificationChannelData>  $channels
     * @param  list<array{category: string, modes: list<string>}>  $grid
     * @param  array{quiet_start: string|null, quiet_end: string|null, quiet_weekends: bool, digest_time: string, digest_weekdays: list<int>|null, timezone: string}  $schedule
     * @param  list<array{id: int, name: string, client_id: int, client_name: string, host: string, url: string, icon_url: string|null, icon_backing: SiteIconBacking, identity: array{label: string, detail: string}}>  $sites  sites the recipient can be alerted about, for site exceptions
     * @param  list<array{id: int, name: string}>  $clients  team scope choices
     * @param  list<ChannelTypeData>  $channel_types  the types this recipient's channels may use (SaveNotificationChannel::offers); the
     *                                                account email is never edited here, so every editable channel's type is listed
     * @param  list<string>|null  $migration_report  stored once by the 2026-10-30 conversion; team page only
     */
    public function __construct(
        public readonly RecipientKind $recipient_kind,
        public readonly ?int $recipient_id,
        public readonly string $recipient_name,
        public readonly array $channels,
        public readonly array $grid,
        public readonly array $schedule,
        public readonly array $sites,
        public readonly array $clients,
        public readonly array $channel_types,
        public readonly ?array $migration_report = null,
    ) {}
}

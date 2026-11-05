<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Enums\SiteIconBacking;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Readers obtain severity from NotificationEventTypes::severity(), not from the site's current state.
 * Null site_id documents a deleted site: site_name is the localized deleted-site label; client_name is retained.
 */
#[TypeScript]
final class EventData extends Data
{
    /**
     * @param  array<string, string|int|float|bool|null>  $message_params
     * @param  list<CheckRunPresentationPartData>  $changes
     */
    public function __construct(
        public readonly int $id,
        public readonly AlertSeverity $severity,
        public readonly ?int $site_id,
        public readonly string $site_name,
        public readonly ?int $incident_id,
        public readonly ?CheckKey $check,
        public readonly string $type,
        public readonly string $time,
        public readonly string $message,
        public readonly array $message_params,
        public readonly ?int $duration_seconds,
        public readonly string $client_name,
        public readonly ?string $icon_url = null,
        public readonly SiteIconBacking $icon_backing = SiteIconBacking::None,
        public readonly ?int $client_id = null,
        public readonly array $changes = [],
        // The stored run that produced this event, when the recorder named one.
        public readonly ?int $run_id = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentTimelineEntryData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $time,
        public readonly ?EventData $event = null,
        public readonly ?string $note = null,
        public readonly ?string $note_author = null,
        public readonly ?IncidentNotificationGroupData $notification_group = null,
    ) {}
}

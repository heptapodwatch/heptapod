<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ClientNotificationsPageData extends Data
{
    public function __construct(
        public readonly NotificationSettingsPageData $settings,
        public readonly ClientData $client,
    ) {}
}

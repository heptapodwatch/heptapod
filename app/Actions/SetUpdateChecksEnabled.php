<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Settings\InstanceSettings;

final readonly class SetUpdateChecksEnabled
{
    public function __construct(private InstanceSettings $settings) {}

    public function __invoke(bool $enabled): void
    {
        abort_unless(config()->boolean('heptapod.updates.enabled_by_server'), 422);
        $this->settings->updates_enabled = $enabled;
        $this->settings->save();
    }
}

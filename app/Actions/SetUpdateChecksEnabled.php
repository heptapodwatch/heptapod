<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\LiveSignal;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Settings\InstanceSettings;

final readonly class SetUpdateChecksEnabled
{
    public function __construct(private InstanceSettings $settings) {}

    public function __invoke(bool $enabled): void
    {
        if (! config()->boolean('heptapod.updates.enabled_by_server')) {
            throw new ExpectedRefusal(
                status: 422,
                messageKey: 'errors.updates.disabled_by_server',
                errorCode: 'updates_disabled_by_server',
            );
        }
        $this->settings->updates_enabled = $enabled;
        $this->settings->save();
        LiveSignal::forUpdates();
    }
}

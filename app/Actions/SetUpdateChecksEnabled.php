<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Queries\UpdateStatusReader;
use Heptapod\Settings\InstanceSettings;

final readonly class SetUpdateChecksEnabled
{
    public function __construct(private InstanceSettings $settings, private UpdateStatusReader $reader) {}

    public function __invoke(bool $enabled): void
    {
        if (! config()->boolean('heptapod.updates.enabled_by_server')) {
            throw new ExpectedRefusal(
                status: 422,
                messageKey: 'errors.updates.disabled_by_server',
                errorCode: 'updates_disabled_by_server',
            );
        }
        // Under the summary lock, and the summary cleared when switching off: no in-flight check can show it again.
        $this->reader->withSummaryLock(function () use ($enabled): void {
            $this->settings->updates_enabled = $enabled;
            $this->settings->save();
        });
        $this->reader->primeShellSummary();
    }
}

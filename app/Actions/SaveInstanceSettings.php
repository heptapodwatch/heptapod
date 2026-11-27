<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Settings\InstanceSettings;

final readonly class SaveInstanceSettings
{
    public function __construct(private InstanceSettings $settings) {}

    /** A null design, zone or servers switch keeps the saved one: a PATCH that names only the instance changes only the name. */
    public function __invoke(string $name, ?MailDesign $mailDesign, ?string $timezone = null, ?bool $serversEnabled = null): void
    {
        if ($serversEnabled !== null && ! config()->boolean('heptapod.servers.enabled_by_server')) {
            throw new ExpectedRefusal(status: 422, messageKey: 'errors.servers.disabled_by_server', errorCode: 'servers_disabled_by_server');
        }
        $this->settings->name = $name;
        if ($serversEnabled !== null) {
            $this->settings->servers_enabled = $serversEnabled;
        }
        if ($mailDesign instanceof MailDesign) {
            $this->settings->mail_design = $mailDesign;
        }
        if ($timezone !== null) {
            $this->settings->timezone = $timezone;
        }
        $this->settings->save();
    }
}

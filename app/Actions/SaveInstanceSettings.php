<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Settings\InstanceSettings;

final readonly class SaveInstanceSettings
{
    public function __construct(private InstanceSettings $settings) {}

    /** A null design keeps the saved one: a PATCH that names only the instance changes only the name. */
    public function __invoke(string $name, ?MailDesign $mailDesign): void
    {
        $this->settings->name = $name;
        if ($mailDesign instanceof MailDesign) {
            $this->settings->mail_design = $mailDesign;
        }
        $this->settings->save();
    }
}

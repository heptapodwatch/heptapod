<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Models\Client;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;

final readonly class SaveInstanceSettings
{
    public function __construct(private InstanceSettings $settings) {}

    /**
     * A null design or zone keeps the saved one: a PATCH that names only the instance changes only the name. A changed
     * zone also becomes the Internal client's zone while that client is still on the UTC it was installed with.
     */
    public function __invoke(string $name, ?MailDesign $mailDesign, ?string $timezone = null): void
    {
        DB::transaction(function () use ($name, $mailDesign, $timezone): void {
            $this->settings->name = $name;
            if ($mailDesign instanceof MailDesign) {
                $this->settings->mail_design = $mailDesign;
            }
            if ($timezone !== null && $timezone !== $this->settings->timezone) {
                $this->settings->timezone = $timezone;
                Client::query()->where('is_internal', true)->where('timezone', 'UTC')->first()?->update(['timezone' => $timezone]);
            }
            $this->settings->save();
        });
    }
}

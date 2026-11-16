<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\TableBlock;
use Heptapod\Settings\InstanceSettings;

/**
 * The example Down alert of the Settings → Email preview: fixed sample facts in the real alert mail shape, so the preview
 * works on an instance that has never sent an alert.
 */
final readonly class EmailPreviewSample
{
    public function __construct(private InstanceSettings $settings) {}

    public function alert(string $locale, MailDesign $design): MailModel
    {
        $line = static fn (string $key): string => __('mail_branding.sample.'.$key, locale: $locale);
        $url = rtrim(config()->string('app.url'), '/');

        return new MailModel(locale: $locale, subject: $line('subject'), preheader: $line('reason'), headline: $line('headline'),
            footerWhy: $line('why'), instance: $this->settings->name.' · '.parse_url($url, PHP_URL_HOST), tone: MailTone::Down,
            client: $line('client'), site: $line('site'), chip: $line('chip'), stateMeta: $line('state'),
            blocks: [new TableBlock($line('table'), [$line('time'), $line('result'), $line('response')],
                [[new Cell('14:02 UTC', mono: true), new Cell($line('reason'), mono: true, tone: MailTone::Down), new Cell('370 ms', mono: true)]])],
            primary: ['label' => $line('open_incident'), 'url' => $url.'/incidents'], secondary: ['label' => $line('open_check'), 'url' => $url.'/sites'],
            routingLabel: $line('change_notifications'), routingUrl: $url.'/settings/notifications', design: $design);
    }
}

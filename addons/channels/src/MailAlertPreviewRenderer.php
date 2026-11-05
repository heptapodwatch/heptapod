<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\AlertPreviewRenderer;
use Heptapod\Contracts\Mail\AlertMailContext;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Contracts\Notifications\AlertMailRenderer;
use Heptapod\Contracts\Notifications\AlertMessage;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Illuminate\Mail\Mailable;

/** One rendering path for sent alert mail and the incident page preview: both render the same block MailModel. */
final readonly class MailAlertPreviewRenderer implements AlertMailRenderer, AlertPreviewRenderer
{
    public function __construct(private MailRenderer $renderer, private AlertMailBuilder $builder) {}

    public function mailable(AlertMessage $message, DestinationConfig $destination): Mailable
    {
        return $this->renderer->mailable($this->model($message, $destination->locale, $destination->timezone, $destination->instanceName, $destination->instanceUrl));
    }

    public function render(AlertMessage $message, string $locale, string $timezone, string $instanceName, string $instanceUrl): array
    {
        return $this->renderer->preview($this->model($message, $locale, $timezone, $instanceName, $instanceUrl));
    }

    /**
     * Messages built for a mail destination carry a MailModel or an alert mail context; anything else is a caller bug.
     * Automatic alert content (check and evidence data) gets the body limits; the channel test send is account mail.
     */
    private function model(AlertMessage $message, string $locale, string $timezone, string $instanceName, string $instanceUrl): MailModel
    {
        if ($message->mail instanceof MailModel) {
            return $message->isTest ? $message->mail : MailTextLimits::automatic($message->mail);
        }
        if (! $message->mailContext instanceof AlertMailContext) {
            throw new \LogicException('An alert mail needs a block mail or an alert mail context; build the message for its mail destination.');
        }

        return MailTextLimits::automatic($this->builder->build(new RenderedAlert($message, $locale, $timezone, $instanceName, $instanceUrl)));
    }
}

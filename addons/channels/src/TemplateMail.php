<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Mail\MailBranding;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailLogo;
use Heptapod\Contracts\Mail\MailModel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class TemplateMail extends Mailable
{
    public function __construct(public readonly MailModel $model, public readonly MailBranding $branding)
    {
        $this->locale($model->locale);
        // Plain carries no images at all, so no unused CID parts either.
        if ($model->design === MailDesign::Plain) {
            return;
        }
        $this->withSymfonyMessage(function (Email $email): void {
            $logo = $this->branding->logo;
            if ($logo instanceof MailLogo) {
                $email->addPart($this->logoPart($logo, 'brand-logo'));
                if ($this->branding->logoDark instanceof MailLogo) {
                    $email->addPart($this->logoPart($this->branding->logoDark, 'brand-logo-dark'));
                }
            } else {
                $email->addPart(new DataPart(BladeMailRenderer::lockup(), 'lockup.png', 'image/png')->asInline()->setContentId('lockup@heptapod'));
            }
            if ($this->model->avatarPng !== null) {
                $cid = $this->model->avatarCid ?? throw new \LogicException('Avatar CID missing.');
                $email->addPart(new DataPart($this->model->avatarPng, $cid.'.png', 'image/png')->asInline()->setContentId($cid.'@heptapod'));
            }
        });
    }

    public function envelope(): Envelope
    {
        // The From address stays server configuration; only its display name is the operator's.
        return new Envelope(from: new Address(config()->string('mail.from.address'), $this->branding->senderName ?? config()->string('mail.from.name')),
            subject: $this->model->subject);
    }

    public function content(): Content
    {
        return new Content(view: $this->model->design === MailDesign::Plain ? 'channels::mail.plain' : 'channels::mail.skeleton', text: 'channels::mail.text',
            with: ['mail' => $this->model, 'branding' => $this->branding]);
    }

    private function logoPart(MailLogo $logo, string $cid): DataPart
    {
        $bytes = $logo->bytes ?? throw new \LogicException('A sent mail logo needs its image.');

        return new DataPart($bytes, $logo->filename($cid), $logo->mime)->asInline()->setContentId($cid.'@heptapod');
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailModel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class TemplateMail extends Mailable
{
    public function __construct(public readonly MailModel $model)
    {
        $this->locale($model->locale);
        // Plain carries no images at all, so no unused CID parts either.
        if ($model->design === MailDesign::Plain) {
            return;
        }
        $this->withSymfonyMessage(function (Email $email): void {
            $email->addPart(new DataPart(BladeMailRenderer::lockup(), 'lockup.png', 'image/png')->asInline()->setContentId('lockup@heptapod'));
            if ($this->model->avatarPng !== null) {
                $cid = $this->model->avatarCid ?? throw new \LogicException('Avatar CID missing.');
                $email->addPart(new DataPart($this->model->avatarPng, $cid.'.png', 'image/png')->asInline()->setContentId($cid.'@heptapod'));
            }
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->model->subject);
    }

    public function content(): Content
    {
        return new Content(view: $this->model->design === MailDesign::Plain ? 'channels::mail.plain' : 'channels::mail.skeleton', text: 'channels::mail.text', with: ['mail' => $this->model]);
    }
}

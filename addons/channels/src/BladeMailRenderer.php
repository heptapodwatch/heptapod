<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailRenderer;

final class BladeMailRenderer implements MailRenderer
{
    public function mailable(MailModel $mail): TemplateMail
    {
        // Every mail keeps the subject limit; body limits belong to automatic alert mail (MailAlertPreviewRenderer).
        return new TemplateMail(MailTextLimits::subject($mail));
    }

    public function preview(MailModel $mail): array
    {
        $mailable = $this->mailable($mail);
        if ($mail->design === MailDesign::Plain) {
            return ['subject' => $mailable->model->subject, 'html' => $mailable->render()];
        }
        $images = ['cid:lockup@heptapod' => 'data:image/png;base64,'.base64_encode(self::lockup())];
        if ($mail->avatarPng !== null) {
            $images['cid:'.$mail->avatarCid.'@heptapod'] = 'data:image/png;base64,'.base64_encode($mail->avatarPng);
        }

        return ['subject' => $mailable->model->subject, 'html' => strtr($mailable->render(), $images)];
    }

    public static function lockup(): string
    {
        $png = file_get_contents(__DIR__.'/../resources/mail/lockup.png');

        return $png === false ? throw new \RuntimeException('Email lockup PNG missing.') : $png;
    }
}

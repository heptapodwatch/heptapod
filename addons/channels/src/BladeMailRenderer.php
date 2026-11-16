<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Mail\MailBranding;
use Heptapod\Contracts\Mail\MailBrandingSource;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailLogo;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailRenderer;

final readonly class BladeMailRenderer implements MailRenderer
{
    public function __construct(private MailBrandingSource $branding) {}

    public function mailable(MailModel $mail): TemplateMail
    {
        // Every mail keeps the subject limit; body limits belong to automatic alert mail (MailAlertPreviewRenderer).
        return new TemplateMail(MailTextLimits::subject($mail), $this->branding->current());
    }

    public function preview(MailModel $mail, ?MailBranding $branding = null): array
    {
        $branding ??= $this->branding->current();
        $mailable = new TemplateMail(MailTextLimits::subject($mail), $branding);
        if ($mail->design === MailDesign::Plain) {
            return ['subject' => $mailable->model->subject, 'html' => $mailable->render()];
        }
        $images = $branding->logo instanceof MailLogo ? [] : ['cid:lockup@heptapod' => $this->dataUri('image/png', self::lockup())];
        foreach (['brand-logo' => $branding->logo, 'brand-logo-dark' => $branding->logoDark] as $cid => $logo) {
            // A draft logo has no bytes yet: the settings page swaps in the browser's copy.
            if ($logo instanceof MailLogo && $logo->bytes !== null) {
                $images['cid:'.$cid.'@heptapod'] = $this->dataUri($logo->mime, $logo->bytes);
            }
        }
        if ($mail->avatarPng !== null) {
            $images['cid:'.$mail->avatarCid.'@heptapod'] = $this->dataUri('image/png', $mail->avatarPng);
        }

        return ['subject' => $mailable->model->subject, 'html' => strtr($mailable->render(), $images)];
    }

    public static function lockup(): string
    {
        $png = file_get_contents(__DIR__.'/../resources/mail/lockup.png');

        return $png === false ? throw new \RuntimeException('Email lockup PNG missing.') : $png;
    }

    private function dataUri(string $mime, string $bytes): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}

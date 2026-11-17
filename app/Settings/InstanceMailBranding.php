<?php

declare(strict_types=1);

namespace Heptapod\Settings;

use Heptapod\Contracts\Mail\MailBranding;
use Heptapod\Contracts\Mail\MailBrandingSource;
use Heptapod\Contracts\Mail\MailLogo;
use Illuminate\Support\Facades\Storage;

/** The branding saved on Settings → Email; logo files live on the local disk, which the backup includes. */
final readonly class InstanceMailBranding implements MailBrandingSource
{
    public const string Disk = 'local';

    public function __construct(private InstanceSettings $settings) {}

    public function current(): MailBranding
    {
        $settings = $this->settings;

        return new MailBranding($settings->mail_sender_name, $settings->mail_accent, $settings->mail_footer,
            $this->logo($settings->mail_logo_path, $settings->mail_logo_width, $settings->mail_logo_height),
            $this->logo($settings->mail_logo_dark_path, $settings->mail_logo_dark_width, $settings->mail_logo_dark_height));
    }

    public static function mime(string $path): string
    {
        return str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';
    }

    private function logo(?string $path, ?int $width, ?int $height): ?MailLogo
    {
        if ($path === null) {
            return null;
        }
        if ($width === null || $height === null) {
            throw new \LogicException('A saved mail logo needs its rendered size.');
        }

        return new MailLogo(Storage::disk(self::Disk)->get($path) ?? throw new \RuntimeException('Mail logo file missing: '.$path), self::mime($path), $width, $height);
    }
}

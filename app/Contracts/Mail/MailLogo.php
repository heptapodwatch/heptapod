<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

/** An operator logo for Branded mail, with the size it is rendered at in the header (24 px high, at most 200 px wide). */
final readonly class MailLogo
{
    /** @param ?string $bytes the PNG or JPEG file; null only for an unsaved draft in the settings preview, whose image the browser fills in */
    public function __construct(public ?string $bytes, public string $mime, public int $width, public int $height)
    {
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new \LogicException('A mail logo is PNG or JPEG.');
        }
    }

    public function filename(string $name): string
    {
        return $name.($this->mime === 'image/png' ? '.png' : '.jpg');
    }
}

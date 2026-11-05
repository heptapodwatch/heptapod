<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class LetterTile
{
    public function __construct(public string $text, public string $background, public string $foreground) {}
}

<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class Cell
{
    /**
     * @param  ?string  $badge  a short marker kept on one line after the text, such as "new"
     * @param  bool  $keepOnOneLine  short label text (a kind or a state word) that must not break mid-word in a narrow column
     * @param  ?string  $plainHtmlText  a shorter display for the Plain HTML table only; the text part and Branded always show $text
     */
    public function __construct(public string $text, public bool $mono = false, public ?MailTone $tone = null, public ?string $badge = null, public bool $keepOnOneLine = false,
        public ?string $plainHtmlText = null) {}
}

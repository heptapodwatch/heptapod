<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class Cell
{
    private const string InlineCode = '/`([^`\n]+)`/';

    /**
     * @param  ?string  $badge  a short marker kept on one line after the text, such as "new"
     * @param  bool  $keepOnOneLine  short label text (a kind or a state word) that must not break mid-word in a narrow column
     * @param  ?string  $plainHtmlText  a shorter display for the Plain HTML table only; the text part and Branded always show $text
     * @param  bool  $inlineCode  $text marks code with Markdown backtick pairs: HTML shows them as code, the text part drops the backticks
     */
    public function __construct(public string $text, public bool $mono = false, public ?MailTone $tone = null, public ?string $badge = null, public bool $keepOnOneLine = false,
        public ?string $plainHtmlText = null, public bool $inlineCode = false) {}

    /** @return list<array{text: string, code: bool}> */
    public function segments(): array
    {
        if (! $this->inlineCode) {
            return [['text' => $this->text, 'code' => false]];
        }
        $parts = preg_split(self::InlineCode, $this->text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \RuntimeException('Mail cell split failed.');
        }
        $segments = [];
        foreach ($parts as $index => $part) {
            if ($part !== '') {
                $segments[] = ['text' => $part, 'code' => $index % 2 === 1];
            }
        }

        return $segments;
    }

    public function plainText(): string
    {
        return $this->inlineCode ? (preg_replace(self::InlineCode, '$1', $this->text) ?? throw new \RuntimeException('Mail cell replace failed.')) : $this->text;
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\DiffBlock;
use Heptapod\Contracts\Mail\EvidenceBlock;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\ListBlock;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Mail\ScoresBlock;
use Heptapod\Contracts\Mail\TableBlock;

/**
 * Byte limits of block mail, the same as RenderedAlert::RENDER_LIMITS for the other channels.
 *
 * - subject(): every mail's subject, at most 1000 bytes.
 * - automatic(): automatic alert mail built from check and evidence data (alert, recovery, digest, catch-up, retained):
 *   every rendered text value at most 1000 bytes. Site, check and target text can be arbitrarily long there.
 *
 * Human-written content (client-message notes, system issue text, account mail) is bounded by its own validation and is
 * never cut here.
 */
final class MailTextLimits
{
    public const int Text = RenderedAlert::RENDER_LIMITS['title'];

    public static function subject(MailModel $mail): MailModel
    {
        return strlen($mail->subject) <= self::Text ? $mail : self::copy($mail, ['subject' => self::text($mail->subject)]);
    }

    public static function automatic(MailModel $mail): MailModel
    {
        return self::copy($mail, ['subject' => self::text($mail->subject), 'preheader' => self::text($mail->preheader),
            'headline' => self::text($mail->headline), 'footerWhy' => self::text($mail->footerWhy), 'instance' => self::text($mail->instance),
            'lead' => self::optional($mail->lead), 'client' => self::optional($mail->client), 'site' => self::optional($mail->site), 'chip' => self::optional($mail->chip),
            'stateMeta' => self::optional($mail->stateMeta), 'blocks' => array_map(self::block(...), $mail->blocks),
            'primary' => $mail->primary === null ? null : ['label' => self::text($mail->primary['label']), 'url' => $mail->primary['url']],
            'secondary' => $mail->secondary === null ? null : ['label' => self::text($mail->secondary['label']), 'url' => $mail->secondary['url']],
            'routingLabel' => self::optional($mail->routingLabel)]);
    }

    /** @param array<string, mixed> $changes */
    private static function copy(MailModel $mail, array $changes): MailModel
    {
        /** @var array{locale: string, subject: string, preheader: string, headline: string, footerWhy: string, instance: string} $values */
        $values = [...get_object_vars($mail), ...$changes];

        return new MailModel(...$values);
    }

    private static function block(EvidenceBlock $block): EvidenceBlock
    {
        $cell = static fn (Cell $cell): Cell => new Cell(self::text($cell->text), $cell->mono, $cell->tone, self::optional($cell->badge), $cell->keepOnOneLine, self::optional($cell->plainHtmlText));

        return match (true) {
            $block instanceof NoteBlock => new NoteBlock(self::text($block->text), $block->tone),
            $block instanceof ListBlock => new ListBlock(self::text($block->title), array_map(static fn (array $item): array => ['text' => self::text($item['text']), 'meta' => self::text($item['meta'])], $block->items)),
            $block instanceof FactsBlock => new FactsBlock(self::text($block->title), array_map(static fn (array $row): array => ['label' => self::text($row['label']), 'value' => $cell($row['value'])], $block->rows)),
            $block instanceof TableBlock => new TableBlock(self::text($block->title), array_map(self::text(...), $block->head),
                array_map(static fn (array $row): array => array_map($cell, $row), $block->rows), self::optional($block->more)),
            $block instanceof DiffBlock => new DiffBlock(self::text($block->title), array_map(static fn (array $line): array => [...$line, 'text' => self::text($line['text'])], $block->lines),
                $block->action === null ? null : ['label' => self::text($block->action['label']), 'url' => $block->action['url']]),
            $block instanceof ScoresBlock => new ScoresBlock(self::text($block->title), array_map(static fn (array $item): array => [...$item, 'label' => self::text($item['label'])], $block->items), self::optional($block->note)),
            default => throw new \LogicException('Unbounded mail block type: '.$block::class),
        };
    }

    private static function optional(?string $text): ?string
    {
        return $text === null ? null : self::text($text);
    }

    private static function text(string $text): string
    {
        // mb_strcut never splits a UTF-8 sequence; the ellipsis marks the cut.
        return strlen($text) <= self::Text ? $text : mb_strcut($text, 0, self::Text - strlen('…'), 'UTF-8').'…';
    }
}

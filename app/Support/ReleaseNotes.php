<?php

declare(strict_types=1);

namespace Heptapod\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;

/**
 * Reads a Heptapod release note: the parts an Owner needs before upgrading as plain text, and the full note as
 * safe HTML. The notes follow one shape (`.agents/skills/heptapod-release-notes`): a headline sentence, then `## `
 * sections with `- ` bullets. The body comes from the network: only capped plain text or the sanitized HTML below
 * leaves this class.
 */
final class ReleaseNotes
{
    private const int MaxBodyBytes = 65536;

    /** The stored full note; a longer one is cut and links to GitHub for the rest. */
    private const int MaxNotesBytes = 16384;

    private const int MaxHeadline = 280;

    private const int MaxItem = 400;

    private const int MaxItems = 8;

    /** @return array{headline: string|null, before: list<string>, security: list<string>} */
    public static function parse(string $body): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", mb_scrub(substr($body, 0, self::MaxBodyBytes), 'UTF-8')));
        $headline = null;
        $section = null;
        $before = [];
        $security = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.*)$/u', $line, $heading) === 1) {
                $section = mb_strtolower(self::text($heading[1], self::MaxHeadline));

                continue;
            }
            if (preg_match('/^\s*[-*+]\s+(.*)$/u', $line, $bullet) === 1) {
                $item = self::text($bullet[1], self::MaxItem);
                if ($item === '' || $section === 'upgrade') {
                    continue;
                }
                if ($section === 'before you upgrade') {
                    $before[] = $item;
                }
                // The prefix only classifies the item; the list heading already says Security.
                $prefixed = preg_match('/^security:\s*/i', $item, $prefix) === 1;
                if ($section === 'security' || $prefixed) {
                    $security[] = $prefixed ? ucfirst(substr($item, strlen($prefix[0]))) : $item;
                }

                continue;
            }
            if ($section === null && $headline === null && trim($line) !== '') {
                $headline = self::text($line, self::MaxHeadline);
            }
        }

        return ['headline' => $headline === '' ? null : $headline,
            'before' => array_slice($before, 0, self::MaxItems), 'security' => array_slice($security, 0, self::MaxItems)];
    }

    /**
     * The stored note: C0 control characters other than tab and newline removed first (the database refuses a null
     * byte in a JSON column, so one would lose the whole check), then capped.
     *
     * @return array{body: string, body_truncated: bool}
     */
    public static function body(string $markdown): array
    {
        $clean = (string) preg_replace('/[\x00-\x08\x0B-\x1F]/', '', $markdown);

        return ['body' => mb_strcut($clean, 0, self::MaxNotesBytes, 'UTF-8'), 'body_truncated' => strlen($clean) > self::MaxNotesBytes];
    }

    /**
     * The full note as HTML: raw HTML stripped, unsafe links without a target, images as their alt text so nothing
     * loads from elsewhere, and every link opening in a new tab without referrer or ranking credit.
     */
    public static function html(string $markdown): string
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 10]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addRenderer(Image::class, new class implements NodeRendererInterface
        {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                $text = '';
                foreach ($node->iterator() as $child) {
                    if ($child instanceof AbstractStringContainer) {
                        $text .= $child->getLiteral();
                    }
                }

                return Xml::escape($text);
            }
        }, 10);
        $environment->addEventListener(DocumentParsedEvent::class, static function (DocumentParsedEvent $event): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof Link) {
                    $node->data->set('attributes/target', '_blank');
                    $node->data->set('attributes/rel', 'noopener noreferrer nofollow');
                }
            }
        });

        return new MarkdownConverter($environment)->convert(mb_scrub($markdown, 'UTF-8'))->getContent();
    }

    /** Markdown and HTML to one line of plain text. */
    private static function text(string $markdown, int $limit): string
    {
        $text = strip_tags($markdown);
        // Images and links keep their visible text only.
        $text = (string) preg_replace('/!?\[([^\]]*)\]\((?:[^()]|\([^()]*\))*\)/u', '$1', $text);
        $text = str_replace(['**', '__', '~~', '`'], '', $text);
        // Control characters and bidirectional overrides could disguise the text.
        $text = (string) preg_replace('/[\p{Cc}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }
}

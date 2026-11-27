<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\Notifications\AlertMessage;
use Heptapod\Contracts\SummaryParameters;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use NotificationChannels\Telegram\TelegramMessage;

/** Shared rendering has no destination credentials and does not change the process locale. */
final class RenderedAlert extends Notification
{
    public const array RENDER_LIMITS = ['title' => 1000, 'body' => 16000, 'site_url' => 2048];

    // https://core.telegram.org/bots/api#sendmessage
    public const int TELEGRAM_LIMIT = 4096;

    // https://discord.com/developers/docs/resources/message#embed-object-embed-limits
    public const array DISCORD_LIMITS = ['title' => 256, 'body' => 4096];

    // https://docs.slack.dev/reference/block-kit/blocks/header-block/
    // https://docs.slack.dev/reference/block-kit/blocks/section-block/
    public const array SLACK_LIMITS = ['title' => 150, 'body' => 3000, 'field' => 2000];

    // https://pushover.net/api#messages
    public const array PUSHOVER_LIMITS = ['title' => 250, 'body' => 1024];

    // https://docs.ntfy.sh/publish/#message-length
    public const int NTFY_LIMIT = 4096;

    // https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/connectors-using
    // JSON can expand each input byte sixfold. These text budgets leave room
    // for the envelope; the channel also checks the complete serialized body.
    public const array TEAMS_LIMITS = ['title' => 150, 'body' => 4000, 'payload' => 27000];

    public function __construct(
        public readonly AlertMessage $message,
        public readonly string $destinationLocale,
        public readonly string $destinationTimezone,
        public readonly string $instanceName,
        public readonly string $instanceUrl,
        private readonly bool $machineReadable = false,
    ) {}

    public function title(bool $markdown = false, int $limit = self::RENDER_LIMITS['title']): string
    {
        $params = $this->message->titleParams;
        if (isset($params['check']) && $this->message->checkType !== null) {
            $params['check'] = $this->checkName();
        }
        $key = $this->message->titleKey;
        if ($this->message->recoveredAt instanceof CarbonImmutable && $this->message->downtimeSeconds !== null) {
            $params['since'] = $this->message->since->setTimezone($this->destinationTimezone)->format('H:i');
            $params['until'] = $this->message->recoveredAt->setTimezone($this->destinationTimezone)->format('H:i');
            $params['duration'] = $this->duration($this->message->downtimeSeconds);
        }
        if ($this->message->cause === 'errored' && ! isset($params['component'])) {
            $key = 'notifications.errored_title';
            $params = ['site' => $this->siteLabel(), 'check' => $this->checkName()];
        } elseif ($this->isNonUptimeCheck()) {
            $key = match ($key) {
                'notifications.recovered_title' => 'notifications.resolved_title',
                'notifications.still_down_title' => 'notifications.still_failing_title',
                default => $key,
            };
        }
        $title = $this->translate($key, $params, $markdown);

        $text = ($this->message->isTest ? $this->translate('notifications.test_prefix').' ' : '').$title;

        return $this->bounded($text, $limit, '…');
    }

    public function headline(): string
    {
        if ($this->message->systemFacts !== null) {
            return $this->message->systemFacts[0] ?? '';
        }
        $check = $this->message->checkType === null ? null : CheckKey::from($this->message->checkType);
        if ($this->message->presentation instanceof CheckRunPresentationData) {
            return CheckHeadline::format($this->message->presentation, $this->destinationLocale, $check);
        }
        $summary = $this->translate($this->message->summaryKey, SummaryParameters::localizedMeasurements($this->message->summaryParams, $this->destinationLocale));

        return $check === null ? $summary : CheckHeadline::prefix($check, $summary, $this->destinationLocale);
    }

    /** @return list<string> */
    public function changes(): array
    {
        return array_map(fn (CheckRunPresentationPartData $part): string => CheckHeadline::format(new CheckRunPresentationData([$part]), $this->destinationLocale), $this->message->presentation->changes ?? []);
    }

    /** @return list<string> */
    public function presentationDetails(): array
    {
        return array_map(fn (CheckRunPresentationPartData $part): string => CheckHeadline::format(new CheckRunPresentationData([$part]), $this->destinationLocale), $this->message->presentation->details ?? []);
    }

    public function details(bool $markdown = false, int $limit = self::RENDER_LIMITS['body']): string
    {
        return $this->bounded(implode("\n", $this->detailLines($markdown)), $limit, $this->translate('notifications.truncated'));
    }

    /** @return list<string> */
    private function detailLines(bool $markdown): array
    {
        // A system issue has no site or check: its localized facts are the whole body.
        if ($this->message->systemFacts !== null) {
            return $markdown ? array_map($this->escapeMarkdown(...), $this->message->systemFacts) : $this->message->systemFacts;
        }
        $lines = $this->narrativeLines($markdown);
        if ($this->message->checkType !== null) {
            $lines[] = $this->translate('notifications.check', ['check' => $this->checkName()], $markdown);
        }
        $lines[] = $this->translate('notifications.site_url', ['url' => $this->siteUrl()], $markdown);
        $lines[] = $this->translate('notifications.since', ['since' => $this->machineReadable ? $this->message->since->toIso8601String() : $this->dateTime($this->message->since)]);
        if ($this->message->downtimeSeconds !== null) {
            $lines[] = $this->durationLine();
        }

        return $lines;
    }

    /** @return list<string> */
    private function narrativeLines(bool $markdown): array
    {
        $params = $this->machineReadable
            ? $this->message->summaryParams
            : SummaryParameters::localized($this->message->summaryParams, $this->destinationLocale);
        if ($this->message->checkTypes !== null) {
            $params['checks'] = implode(', ', array_map(fn (string $key): string => $this->machineReadable
                ? $key : $this->translate('checks.'.$key.'.label'), $this->message->checkTypes));
        }
        if (! $this->machineReadable && isset($params['at']) && is_string($params['at'])) {
            $params['at'] = $this->dateTime(CarbonImmutable::parse($params['at']));
        }
        $lines = [$this->message->cause === 'errored' && str_starts_with($this->message->summaryKey, 'notifications.')
            ? $this->translate('notifications.check_error')
            : $this->translate($this->message->summaryKey, $params, $markdown)];
        if ($this->message->recoveredAt instanceof CarbonImmutable) {
            $lines[] = $this->translate('notifications.late_fold_context', [], $markdown);
        }
        if ($this->message->maintenanceEnded) {
            $lines[] = $this->translate('notifications.maintenance_ended_context', [], $markdown);
        }
        if ($this->message->affectedChecks !== []) {
            $lines[] = $this->translate('notifications.also_affected', ['checks' => implode(', ', array_map(
                fn (string $key): string => $this->machineReadable ? $key : $this->translate('checks.'.$key.'.label'),
                $this->message->affectedChecks,
            ))], $markdown);
        }
        if ($this->message->recheckingChecks !== []) {
            $lines[] = $this->translate('notifications.rechecking', ['checks' => implode(', ', array_map(
                fn (string $key): string => $this->machineReadable ? $key : $this->translate('checks.'.$key.'.label'),
                $this->message->recheckingChecks,
            ))], $markdown);
        }

        return $lines;
    }

    private function durationLine(): string
    {
        $seconds = $this->message->downtimeSeconds ?? throw new \LogicException('Duration line requires downtime seconds.');
        if ($this->machineReadable) {
            return $this->translate('notifications.downtime_seconds', ['seconds' => $seconds]);
        }

        return $this->translate(
            $this->isNonUptimeCheck() ? 'notifications.duration' : 'notifications.downtime',
            ['duration' => $this->duration($seconds)],
        );
    }

    private function duration(int $seconds): string
    {
        $parts = [];
        $remaining = $seconds;
        foreach ([86400 => 'days', 3600 => 'hours', 60 => 'minutes', 1 => 'seconds'] as $size => $unit) {
            $count = intdiv($remaining, $size);
            $remaining %= $size;
            if ($count > 0) {
                $parts[] = $this->translate('notifications.duration_unit.'.$unit, ['count' => $count]);
            }
            if (count($parts) === 2) {
                break;
            }
        }

        return $parts === [] ? $this->translate('notifications.duration_unit.seconds', ['count' => 0]) : implode(' ', $parts);
    }

    private function isNonUptimeCheck(): bool
    {
        return $this->message->checkType !== null && $this->message->checkType !== 'uptime';
    }

    private function checkName(): string
    {
        if ($this->message->checkType === null) {
            return $this->translate('notifications.check_name');
        }

        return $this->machineReadable ? $this->message->checkType : $this->translate('checks.'.$this->message->checkType.'.label');
    }

    private function dateTime(CarbonImmutable $at): string
    {
        $local = $at->setTimezone($this->destinationTimezone);
        $local->locale($this->destinationLocale);

        return $local->isoFormat('lll z');
    }

    public function text(int $limit = self::RENDER_LIMITS['body']): string
    {
        $content = $this->title()."\n".$this->details();
        $link = $this->message->link === null ? '' : "\n".$this->message->link;

        return $this->bounded($content, $limit - strlen($link), $this->translate('notifications.truncated')).$link;
    }

    public function siteUrl(): string
    {
        return $this->bounded($this->message->siteUrl, self::RENDER_LIMITS['site_url'], '…');
    }

    public function siteLabel(): string
    {
        return $this->bounded($this->message->siteLabel, self::RENDER_LIMITS['title'], '…');
    }

    private function bounded(string $text, int $limit, string $suffix): string
    {
        if ($limit < strlen($suffix)) {
            throw new \LengthException('Notification link leaves no room for bounded content.');
        }
        if (strlen($text) <= $limit) {
            return $text;
        }

        // Byte limits are conservative for character-based APIs and also protect
        // Slack's package validator; mb_strcut never splits a UTF-8 sequence.
        return mb_strcut($text, 0, $limit - strlen($suffix), 'UTF-8').$suffix;
    }

    /** @param array<string, string|int|float> $params */
    public function translate(string $key, array $params = [], bool $markdown = false): string
    {
        // Count summaries (digest, maintenance catch-up) carry "one|many" forms; choose by their count.
        $count = is_numeric($params['count'] ?? null) ? (int) $params['count'] : null;
        if ($markdown) {
            $params = array_map($this->escapeMarkdown(...), $params);
        }
        $value = $count === null ? trans($key, $params, $this->destinationLocale)
            : trans_choice($key, $count, $params, $this->destinationLocale);
        if (! is_string($value) || $value === $key) {
            throw new \UnexpectedValueException('Missing alert translation: '.$key);
        }

        return $value;
    }

    private function escapeMarkdown(string|int|float $value): string
    {
        $escaped = addcslashes((string) $value, '\\\\`*_{}[]()#+!|>~<');

        // Dots and hyphens are syntax only at a line start; leave ordinary URLs readable.
        return preg_replace('/(^|\n)([ \t]*(?:[0-9]+)?)([.-])/', '$1$2\\\\$3', $escaped)
            ?? throw new \LogicException('Could not escape alert Markdown.');
    }

    /** @return array{title: string, description: string, url?: string, timestamp: string} */
    public function discordEmbed(): array
    {
        return [
            'title' => $this->title(true, self::DISCORD_LIMITS['title']),
            'description' => $this->details(true, self::DISCORD_LIMITS['body']),
            ...($this->message->link === null ? [] : ['url' => $this->message->link]),
            'timestamp' => $this->message->since->toIso8601String(),
        ];
    }

    public function toSlack(): SlackMessage
    {
        $narrative = $this->message->systemFacts ?? $this->narrativeLines(false);
        $sectionText = $this->boundedEscapedSlack(
            implode("\n", array_map($this->slackEscape(...), $narrative)),
            self::SLACK_LIMITS['body'],
            $this->slackEscape($this->translate('notifications.truncated')),
        );
        [$fields, $overflowSiteSection] = $this->slackMetadataBlocks();

        // Keep mrkdwn enabled so explicit `<url|label>` links work in Slack and Mattermost; `verbatim`
        // (in blocks) and `parse: none` (in SlackChannel) stop auto-linking untrusted text or mentions.
        $message = (new SlackMessage)
            ->text($this->slackFallbackText())
            ->unfurlLinks(false)
            ->unfurlMedia(false)
            ->headerBlock($this->title(limit: self::SLACK_LIMITS['title']))
            ->sectionBlock(function (SectionBlock $block) use ($sectionText, $fields): void {
                $block->text($sectionText)->markdown()->verbatim();
                foreach ($fields as $field) {
                    $block->field($field)->markdown()->verbatim();
                }
            });
        if ($overflowSiteSection !== null) {
            $message->sectionBlock(function (SectionBlock $block) use ($overflowSiteSection): void {
                $block->text($overflowSiteSection)->markdown()->verbatim();
            });
        }
        $link = $this->message->link;
        if ($link !== null) {
            $linkText = $this->slackLink($link, $this->slackLinkLabel($link));
            $message->sectionBlock(function (SectionBlock $block) use ($linkText): void {
                $block->text($linkText)->markdown()->verbatim();
            });
        }
        if (trim($this->instanceName) !== '') {
            $message->contextBlock(function (ContextBlock $block): void {
                $block->text($this->bounded($this->instanceName, self::SLACK_LIMITS['body'], '…'));
            });
        }

        return $message;
    }

    /** @return array{list<string>, string|null} */
    private function slackMetadataBlocks(): array
    {
        if ($this->message->systemFacts !== null) {
            return [[], null];
        }
        $fields = [];
        $overflowSiteSection = null;
        if ($this->message->checkType !== null) {
            $fields[] = $this->slackField('notifications.field.check', $this->slackEscape($this->checkName()));
        }
        $sitePrefix = '*'.$this->slackEscape($this->translate('notifications.field.site'))."*\n";
        $fieldLink = $this->slackSiteLink(self::SLACK_LIMITS['field'] - strlen($sitePrefix));
        if ($fieldLink !== null) {
            $fields[] = $sitePrefix.$fieldLink;
        } else {
            // Site URLs up to 2,048 bytes exceed the 2,000-byte field cap; keep them clickable in a 3,000-byte section.
            $sectionBudget = self::SLACK_LIMITS['body'] - strlen($sitePrefix);
            $sectionLink = $this->slackSiteLink($sectionBudget);
            $overflowSiteSection = $sitePrefix.($sectionLink ?? $this->boundedEscapedSlack($this->slackEscape($this->siteUrl()), $sectionBudget, '…'));
        }
        $fields[] = $this->slackField('notifications.field.since', $this->slackEscape($this->dateTime($this->message->since)));
        if ($this->message->downtimeSeconds !== null) {
            $labelKey = $this->isNonUptimeCheck() ? 'notifications.field.duration' : 'notifications.field.downtime';
            $fields[] = $this->slackField($labelKey, $this->slackEscape($this->duration($this->message->downtimeSeconds)));
        }

        return [$fields, $overflowSiteSection];
    }

    private function slackField(string $labelKey, string $escapedValue): string
    {
        $prefix = '*'.$this->slackEscape($this->translate($labelKey))."*\n";

        return $prefix.$this->boundedEscapedSlack($escapedValue, self::SLACK_LIMITS['field'] - strlen($prefix), '…');
    }

    private function slackFallbackText(int $limit = self::SLACK_LIMITS['body']): string
    {
        $link = $this->message->link === null ? '' : "\n".$this->slackLink($this->message->link, $this->slackLinkLabel($this->message->link));
        $bodyLimit = $limit - strlen($link);
        $truncated = $this->slackEscape($this->translate('notifications.truncated'));
        if ($bodyLimit < strlen($truncated)) {
            throw new \LengthException('Notification link leaves no room for bounded content.');
        }
        $title = $this->slackEscape($this->title(limit: self::SLACK_LIMITS['title']));
        if ($this->message->systemFacts !== null) {
            $body = implode("\n", [$title, ...array_map($this->slackEscape(...), $this->message->systemFacts)]);

            return $this->boundedEscapedSlack($body, $bodyLimit, $truncated).$link;
        }
        $beforeSite = [];
        if ($this->message->checkType !== null) {
            $beforeSite[] = $this->slackEscape($this->translate('notifications.check', ['check' => $this->checkName()]));
        }
        $afterSite = [$this->slackEscape($this->translate('notifications.since', ['since' => $this->dateTime($this->message->since)]))];
        if ($this->message->downtimeSeconds !== null) {
            $afterSite[] = $this->slackEscape($this->durationLine());
        }
        $otherMetaBytes = strlen(implode("\n", [...$beforeSite, ...$afterSite])) + 2;
        $sitePrefix = $this->slackEscape($this->translate('notifications.field.site')).': ';
        $siteBudget = max(0, $bodyLimit - strlen($sitePrefix) - $otherMetaBytes - strlen($truncated) - 32);
        $siteLink = $this->slackSiteLink($siteBudget);
        $siteLine = $sitePrefix.($siteLink ?? $this->boundedEscapedSlack($this->slackEscape($this->siteUrl()), max(strlen('…'), $siteBudget), '…'));
        $meta = "\n".implode("\n", [...$beforeSite, $siteLine, ...$afterSite]);
        $narrative = implode("\n", array_map($this->slackEscape(...), $this->narrativeLines(false)));
        $narrativeBudget = $bodyLimit - strlen($title) - 1 - strlen($meta);
        if ($narrativeBudget < strlen($truncated)) {
            $titleBudget = $bodyLimit - strlen($meta) - 1 - strlen($truncated);
            if ($titleBudget >= strlen('…')) {
                $title = $this->boundedEscapedSlack($title, $titleBudget, '…');
                $narrativeBudget = $bodyLimit - strlen($title) - 1 - strlen($meta);
            }
        }
        if ($narrativeBudget >= strlen($truncated)) {
            return $title."\n".$this->boundedEscapedSlack($narrative, $narrativeBudget, $truncated).$meta.$link;
        }

        return $this->boundedEscapedSlack($title."\n".$narrative.$meta, $bodyLimit, $truncated).$link;
    }

    private function slackSiteLink(int $maxBytes): ?string
    {
        $url = $this->siteUrl();
        if (preg_match('#\Ahttps?://\S+\z#i', $url) !== 1) {
            return null;
        }
        $display = preg_replace('#\Ahttps?://#i', '', $url) ?? $url;
        $display = rtrim(preg_replace('/[?#].*\z/', '', $display) ?? $display, '/');
        if ($display === '') {
            $display = $url;
        }
        foreach ([$this->bounded($display, 120, '…'), $this->translate('notifications.open_site')] as $label) {
            $linked = $this->slackLink($url, $label);
            if (strlen($linked) <= $maxBytes) {
                return $linked;
            }
        }

        return null;
    }

    private function slackLinkLabel(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && str_contains($path, '/incidents/')
            ? $this->translate('notifications.open_incident')
            : $this->translate('notifications.view');
    }

    private function slackLink(string $url, string $label): string
    {
        // Percent-encode delimiters that break Slack `<url|label>` or Mattermost's `[label](url)` Markdown rewrite.
        $safeUrl = strtr($url, [
            '&' => '&amp;', '<' => '%3C', '>' => '%3E', '|' => '%7C', ' ' => '%20',
            '(' => '%28', ')' => '%29', '\\' => '%5C',
        ]);
        $safeLabel = strtr($this->slackEscape($label), ['|' => '%7C', '[' => '%5B', ']' => '%5D', '\\' => '%5C']);

        return '<'.$safeUrl.'|'.$safeLabel.'>';
    }

    private function slackEscape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    private function boundedEscapedSlack(string $escaped, int $limit, string $suffix): string
    {
        if ($limit < strlen($suffix)) {
            throw new \LengthException('Notification link leaves no room for bounded content.');
        }
        if (strlen($escaped) <= $limit) {
            return $escaped;
        }
        $cut = mb_strcut($escaped, 0, $limit - strlen($suffix), 'UTF-8');
        $lastOpen = strrpos($cut, '<');
        $lastClose = strrpos($cut, '>');
        if ($lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose)) {
            $cut = substr($cut, 0, $lastOpen);
        }
        $cut = preg_replace('/&[^;]{0,4}$/', '', $cut) ?? $cut;

        return $cut.$suffix;
    }

    public function toTelegram(): TelegramMessage
    {
        return TelegramMessage::create($this->text(self::TELEGRAM_LIMIT))->normal();
    }
}

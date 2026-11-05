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
    public const array SLACK_LIMITS = ['title' => 150, 'body' => 3000];

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
        if ($this->message->siteId === null && isset($params['site'])) {
            $params['site'] = $this->siteLabel();
        }
        $key = $this->message->titleKey;
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
        if ($this->message->checkType !== null) {
            $lines[] = $this->translate('notifications.check', ['check' => $this->checkName()], $markdown);
        }
        if ($this->message->siteId === null) {
            $lines[] = $markdown ? $this->escapeMarkdown($this->siteLabel()) : $this->siteLabel();
        } else {
            $lines[] = $this->translate('notifications.site_url', ['url' => $this->siteUrl()], $markdown);
        }
        $lines[] = $this->translate('notifications.since', ['since' => $this->machineReadable ? $this->message->since->toIso8601String() : $this->dateTime($this->message->since)]);
        if ($this->message->downtimeSeconds !== null) {
            $durationKey = ! $this->machineReadable && $this->isNonUptimeCheck()
                ? 'notifications.duration' : 'notifications.downtime';
            $lines[] = $this->translate($durationKey, ['seconds' => $this->message->downtimeSeconds]);
        }

        return $lines;
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
        $label = $this->message->siteId === null
            ? $this->translate('events.deleted_site', ['label' => $this->message->siteLabel]) : $this->message->siteLabel;

        return $this->bounded($label, self::RENDER_LIMITS['title'], '…');
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

    public function toSlack(): SlackMessage
    {
        $fallback = strtr($this->text(self::SLACK_LIMITS['body']), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);

        $message = (new SlackMessage)->text($fallback)->disableMarkdownParsing()->unfurlLinks(false)->unfurlMedia(false)->headerBlock($this->title(limit: self::SLACK_LIMITS['title']))
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text($this->details(limit: self::SLACK_LIMITS['body']));
            });
        $link = $this->message->link;
        if ($link !== null) {
            $message->sectionBlock(function (SectionBlock $block) use ($link): void {
                $block->text($link);
            });
        }

        return $message;
    }

    public function toTelegram(): TelegramMessage
    {
        return TelegramMessage::create($this->text(self::TELEGRAM_LIMIT))->normal();
    }
}

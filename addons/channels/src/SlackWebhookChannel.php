<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

final class SlackWebhookChannel extends IncomingWebhookChannel
{
    public function key(): string
    {
        return 'slackWebhook';
    }

    public function icon(): string
    {
        return 'hash';
    }

    protected function payload(RenderedAlert $alert): array
    {
        // Mattermost also accepts the text envelope; Slack's Block Kit is not portable.
        return ['text' => strtr($alert->text(RenderedAlert::SLACK_LIMITS['body']), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']),
            'mrkdwn' => false, 'unfurl_links' => false, 'unfurl_media' => false];
    }
}

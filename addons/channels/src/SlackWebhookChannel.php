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
        // Slack incoming webhooks render Block Kit `blocks`; Mattermost ignores `blocks` and renders `text`
        // (translating `<url|label>` links per Mattermost's Slack compatibility docs).
        /** @var array<string, mixed> $payload */
        $payload = $alert->toSlack()->toArray();
        unset($payload['channel']);

        return $payload;
    }
}

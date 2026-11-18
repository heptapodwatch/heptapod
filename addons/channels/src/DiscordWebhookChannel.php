<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

final class DiscordWebhookChannel extends IncomingWebhookChannel
{
    protected const string ProviderUrlRule = 'discord';

    public function key(): string
    {
        return 'discordWebhook';
    }

    public function icon(): string
    {
        return 'message-circle';
    }

    protected function payload(RenderedAlert $alert): array
    {
        return ['embeds' => [$alert->discordEmbed()], 'allowed_mentions' => ['parse' => []]];
    }
}

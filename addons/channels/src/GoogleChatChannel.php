<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

final class GoogleChatChannel extends IncomingWebhookChannel
{
    protected const string ProviderUrlRule = 'google_chat';

    public function key(): string
    {
        return 'googleChat';
    }

    public function icon(): string
    {
        return 'message-circle';
    }

    protected function payload(RenderedAlert $alert): array
    {
        $widgets = [['textParagraph' => ['text' => nl2br(htmlspecialchars($alert->details(limit: 4000)), false)]]];
        if ($alert->message->link !== null) {
            $widgets[] = ['buttonList' => ['buttons' => [[
                'text' => $alert->translate('notifications.view'),
                'onClick' => ['openLink' => ['url' => $alert->message->link]],
            ]]]];
        }

        return ['cardsV2' => [['cardId' => 'heptapod-alert', 'card' => [
            'header' => ['title' => $alert->title(limit: 150)],
            'sections' => [['widgets' => $widgets]],
        ]]]];
    }
}

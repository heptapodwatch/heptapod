<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Enums\RecipientKind;
use Heptapod\Models\NotificationDestination;

/** The "why you receive this" footer and its settings link, shared by every routed notification mail. */
final readonly class MailRouting
{
    /** @return array{why: string, url: string, labelKey: string} */
    public function footer(NotificationDestination $destination, string $receives): array
    {
        $locale = $destination->effectiveLocale();
        $why = $destination->account_mail
            ? __('emails.account_category_why', ['category' => $receives], $locale)
            : __('emails.channel_category_why', ['channel' => $destination->label, 'address' => $destination->destinationConfig()->string('email'),
                'category' => $receives], $locale);

        // "Change notifications" opens the settings page of whoever this channel belongs to.
        $url = match ($destination->recipient_kind) {
            RecipientKind::Team => route('settings.notifications.team'),
            RecipientKind::User => route('settings.notifications.edit'),
            RecipientKind::Client => route('clients.notifications', $destination->client_id ?? throw new \LogicException('A client channel needs its client.')),
        };

        return ['why' => $why, 'url' => $url, 'labelKey' => 'emails.change_notifications'];
    }
}

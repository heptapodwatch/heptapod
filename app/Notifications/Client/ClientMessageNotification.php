<?php

declare(strict_types=1);

namespace Heptapod\Notifications\Client;

use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * A message a person sent to a client (design §4): rendered from the event payload frozen at approval, in the client's
 * language, without app links. Replies go to the sender. The CSV is the file stored at approval; a changed file fails
 * the send loudly instead of delivering something nobody approved.
 */
final class ClientMessageNotification extends Notification
{
    /**
     * @param  array{name: string, address: string}  $replyTo
     * @param  array{disk: string, path: string, sha256: string, filename: string}|null  $attachment
     */
    public function __construct(public readonly MailModel $mail, public readonly array $replyTo, public readonly ?array $attachment) {}

    public function toMail(ChannelRecipient $notifiable): Mailable
    {
        $address = $notifiable->routeNotificationForMail() ?? throw new \LogicException('A mail notification requires a recipient address.');
        $mailable = app(MailRenderer::class)->mailable($this->mail)->to($address)->replyTo($this->replyTo['address'], $this->replyTo['name']);
        if ($this->attachment !== null) {
            $contents = Storage::disk($this->attachment['disk'])->get($this->attachment['path'])
                ?? throw new \RuntimeException('The client message attachment is missing.');
            if (! hash_equals($this->attachment['sha256'], hash('sha256', $contents))) {
                throw new \RuntimeException('The client message attachment changed after approval.');
            }
            $mailable->attachData($contents, $this->attachment['filename'], ['mime' => 'text/csv']);
        }

        return $mailable;
    }
}

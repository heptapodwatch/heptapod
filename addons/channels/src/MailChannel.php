<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Contracts\Mail\MailTransportDiagnosis;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelType;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;

/**
 * The "heptapod.mail" driver. The Laravel mail driver ignores the route for Mailables (vendor MailChannel),
 * so this driver checks that the Notification addressed exactly the recipient's route before it sends.
 */
final readonly class MailChannel implements ChannelType
{
    public function key(): string
    {
        return 'mail';
    }

    public function nameKey(): string
    {
        return 'notifications.channel.mail';
    }

    public function descriptionKey(): string
    {
        return 'notifications.channel.mail.description';
    }

    public function icon(): string
    {
        return 'mail';
    }

    public function configSchema(): Schema
    {
        return new Schema(['email' => new Field(FieldType::String, ['required', 'email'], presentation: new FieldPresentation('channels.field.email'))]);
    }

    public function supportsIdempotencyKey(): bool
    {
        return false;
    }

    public function send(ChannelRecipient $recipient, Notification $notification): DeliveryResult
    {
        $route = $recipient->routeNotificationFor('mail');
        if (! is_string($route) || $route === '') {
            Log::error('Notification mail has no route.', ['exception' => new \LogicException('Mail recipient has no address.'), 'destination_id' => $recipient->destination->destinationId]);

            return new DeliveryResult(DeliveryState::Failed, 'notifications.no_route', diagnosis: __('notifications.no_route', locale: $recipient->destination->locale));
        }
        if (! method_exists($notification, 'toMail')) {
            throw new \LogicException('A mail notification must render toMail().');
        }
        $mail = $notification->toMail($recipient);
        if (! $mail instanceof Mailable) {
            throw new \LogicException('Notification mail must be a Mailable.');
        }
        // Check the final Symfony message: it carries the recipients from to()/cc()/bcc() and from envelope().
        $mail->withSymfonyMessage(static function (Email $message) use ($route): void {
            $recipients = [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];
            if (count($recipients) !== 1 || strcasecmp($recipients[0]->getAddress(), $route) !== 0) {
                throw new \LogicException('Notification mail must be addressed to exactly its recipient route.');
            }
        });
        try {
            // Synchronous mail keeps provider acceptance observable.
            // Resolved per send: the driver is a singleton, the mail manager is not.
            $sent = Mail::mailer()->send($mail);
        } catch (TransportExceptionInterface $exception) {
            $diagnosis = MailTransportDiagnosis::describe($exception);
            Log::warning('Notification transport failed.', ['destination_id' => $recipient->destination->destinationId, 'channel' => 'mail', 'diagnosis' => $diagnosis]);

            return new DeliveryResult(DeliveryState::Failed, 'notifications.transport_failed', diagnosis: $diagnosis);
        }

        // A null SentMessage only comes from the mail fake or a MessageSending veto; the app registers no veto listener.
        return new DeliveryResult(MailDeliveryMode::logOnly() ? DeliveryState::Logged : DeliveryState::Sent, providerMessageId: $sent?->getMessageId());
    }
}

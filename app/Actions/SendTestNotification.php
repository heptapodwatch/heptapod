<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\AlertMessage;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Notifications\AccountMailFactory;
use Heptapod\SystemHealth\MailTransportRecord;
use Illuminate\Support\Str;

final readonly class SendTestNotification
{
    public function __construct(private ChannelRegistry $channels, private AccountMailFactory $accountMails) {}

    /** The caller authorizes through NotificationDestinationPolicy::sendTest; whoever manages a channel may test it. */
    public function execute(NotificationDestination $destination, User $actor): DeliveryResult
    {
        activity('audit')->causedBy($actor)->performedOn($destination)->event('notification.test_requested')
            ->withProperties(['destination_id' => $destination->id])->log('notification.test_requested');
        if ($destination->needs_attention) {
            $reason = $destination->attention_reason ?? throw new \LogicException('A destination needing attention must have a reason.');

            return new DeliveryResult(DeliveryState::Failed, $reason, diagnosis: __($reason, locale: $destination->effectiveLocale()));
        }
        $message = new AlertMessage(
            eventId: 0, severity: AlertSeverity::Info,
            titleKey: 'notifications.test_title', titleParams: [],
            summaryKey: 'notifications.test_summary', summaryParams: [],
            siteId: 0, siteLabel: 'Heptapod', siteUrl: config()->string('app.url'),
            checkType: null, since: CarbonImmutable::now('UTC'), downtimeSeconds: null,
            cause: null, link: route('sites.index'), isTest: true,
            mail: $destination->channel === 'mail' ? $this->accountMails->test($destination, $actor, CarbonImmutable::now('UTC')) : null,
        );

        $result = $this->channels->send($destination->channel, ChannelRecipient::forChannel($destination->destinationConfig()), new AlertNotification($message, 'test:'.Str::uuid()));
        if ($destination->channel === 'mail') {
            $destination->update(['last_outcome' => $result->status === DeliveryState::Sent ? 'accepted'
                : ($result->status === DeliveryState::Logged ? 'logged' : ($result->recipientRejected() ? 'rejected' : 'failed')),
                'last_outcome_diagnosis' => $result->diagnosis, 'last_outcome_at' => CarbonImmutable::now('UTC')]);
            if ($result->reason === 'notifications.transport_failed' && ! $result->recipientRejected()) {
                app(MailTransportRecord::class)->recordFailure(CarbonImmutable::now('UTC'), $result->diagnosis ?? throw new \LogicException('A transport failure must carry a diagnosis.'));
            }
        }

        return $result;
    }
}

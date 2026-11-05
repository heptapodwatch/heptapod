<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Data\Ui\ClientMessageRowData;
use Heptapod\Enums\DeliveryOutcome;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * "Messages to this client" (design §7.3): every delivery to one of the client's channels, newest first, paged by delivery id
 * page over deliveries.(destination_id, id). Manual messages and automatic alerts alike. Members see neither the address nor the
 * channel label (NotificationDestination::identityVisibleTo: a migrated unnamed contact's label is its email).
 */
final readonly class ClientMessagesReader
{
    public const int PageSize = 25;

    /** @return array{rows: list<ClientMessageRowData>, next_before: int|null} */
    public function page(User $viewer, Client $client, ?int $before): array
    {
        // Every channel here is a client channel, so identityVisibleTo() reduces to the viewer's role.
        $visible = $viewer->hasAnyRole(['Owner', 'Admin']);
        $hidden = __('notifications.delivery_hidden_address');
        $hidden = is_string($hidden) ? $hidden : throw new \LogicException('Translations are text.');
        // Page ids first: the newest PageSize + 1 per channel, each read backwards on deliveries (destination_id, id), then merged.
        // The cost follows the client's own deliveries, never the whole table (a client without traffic reads nothing).
        $perChannel = DB::table('deliveries as d')->select('d.id')->whereColumn('d.destination_id', 'channel_ids.id')->where('d.digest', false)
            ->when($before !== null, static fn (Builder $query): Builder => $query->where('d.id', '<', $before))
            ->orderByDesc('d.id')->limit(self::PageSize + 1);
        $pageIds = DB::table('notification_destinations as channel_ids')->where('channel_ids.recipient_kind', 'client')->where('channel_ids.client_id', $client->id)
            ->joinLateral($perChannel, 'newest')->select('newest.id')->orderByDesc('newest.id')->limit(self::PageSize + 1);
        $query = DB::table('deliveries')->joinSub($pageIds, 'page', 'page.id', '=', 'deliveries.id')
            ->join('events', 'events.id', '=', 'deliveries.event_id')
            ->join('notification_destinations as channel', 'channel.id', '=', 'deliveries.destination_id')
            ->leftJoin('client_message_attachments as attachment', 'attachment.event_id', '=', 'events.id')
            ->select(['deliveries.id', 'deliveries.event_id', 'deliveries.destination_id', 'events.check_id', 'events.site_id', 'deliveries.state', 'deliveries.suppressed', 'deliveries.suppression_reason', 'deliveries.last_reason', 'deliveries.last_diagnosis',
                'deliveries.sent_at', 'deliveries.next_attempt_at', 'deliveries.origin', 'deliveries.actor_name', 'deliveries.recipient_snapshot', 'deliveries.site_name', 'deliveries.category',
                'events.type', 'events.occurred_at', 'events.payload', 'channel.label', 'channel.config', 'channel.removed_at'])
            ->selectRaw('attachment.id IS NOT NULL AS has_attachment')
            ->orderByDesc('deliveries.id');
        $rows = $query->get();
        $more = $rows->count() > self::PageSize;
        $result = [];
        foreach ($rows->take(self::PageSize) as $row) {
            /** @var \stdClass&object{id: int, event_id: int, destination_id: int, check_id: int|null, site_id: int|null, state: string, suppressed: bool, suppression_reason: string|null, last_reason: string|null, last_diagnosis: string|null, sent_at: string|null, next_attempt_at: string, origin: string, actor_name: string|null, recipient_snapshot: string|null, site_name: string|null, category: string|null, type: string, occurred_at: string, payload: string, label: string, config: string, removed_at: string|null, has_attachment: bool} $row */
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            /** @var array{address?: string}|null $snapshot */
            $snapshot = $row->recipient_snapshot === null ? null : json_decode($row->recipient_snapshot, true, flags: JSON_THROW_ON_ERROR);
            /** @var array{email?: string} $config */
            $config = json_decode($row->config, true, flags: JSON_THROW_ON_ERROR);
            $manual = str_starts_with($row->type, 'client_message.');
            $deliveryState = DeliveryState::from($row->state);
            $state = match (true) {
                $deliveryState === DeliveryState::Sent => DeliveryOutcome::Accepted,
                $deliveryState === DeliveryState::Logged => DeliveryOutcome::Logged,
                // A rejected or failed attempt stays Failed even when suppression stops its retries; so does a one-off
                // attempt whose outcome was never recorded (a crash or an exception), which will not be tried again.
                $deliveryState === DeliveryState::Failed, $row->suppression_reason === ClaimNotificationDelivery::OneOffAttempted => DeliveryOutcome::Failed,
                $row->suppressed => DeliveryOutcome::Suppressed,
                default => DeliveryOutcome::Pending,
            };
            $address = $snapshot['address'] ?? $config['email'] ?? null;
            $oneOff = $manual && ($payload['one_off_channel_id'] ?? null) === $row->destination_id;
            $result[] = new ClientMessageRowData(id: $row->id, event_id: $row->event_id, at: $this->iso($row->sent_at ?? $row->occurred_at),
                kind: $manual ? substr($row->type, strlen('client_message.')) : 'alert',
                subject: $manual && is_string($payload['subject'] ?? null) ? $payload['subject'] : $this->alertSubject($row->category, $row->site_name),
                origin: $row->origin, actor: $row->actor_name, channel: $visible ? $row->label : $hidden,
                address: $visible ? $address : null,
                state: $state, reason: in_array($state, [DeliveryOutcome::Failed, DeliveryOutcome::Suppressed], true) ? $this->reason($row->last_diagnosis, $row->suppression_reason) : null,
                attachment: $row->has_attachment,
                resend: $manual && $visible && $state === DeliveryOutcome::Failed ? $this->resend($payload, $row->site_id, $row->check_id, $oneOff ? $address : null) : null);
        }

        return ['rows' => $result, 'next_before' => $more && $result !== [] ? end($result)->id : null];
    }

    /**
     * What "Send again" pre-fills: the same subject, note and design; a failed one-off address comes back as the one-off field.
     * A message sent before the Plain design existed was Branded.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resend(array $payload, ?int $siteId, ?int $checkId, ?string $oneOff): array
    {
        $incident = is_array($payload['incident'] ?? null) ? $payload['incident'] : null;

        return ['kind' => $payload['kind'] ?? null, 'site_id' => $siteId, 'check_id' => $checkId, 'incident_id' => $incident['id'] ?? null, 'note' => $payload['note'] ?? '',
            'attach' => $payload['attach'] ?? false, 'design' => $payload['design'] ?? MailDesign::Branded->value, 'one_off' => $oneOff];
    }

    /** An automatic alert reads as what it was about: "Outage · API". */
    private function alertSubject(?string $category, ?string $site): string
    {
        $label = $category === null ? null : __('recipient_settings.category.'.$category.'.label');

        return implode(' · ', array_filter([is_string($label) ? $label : null, $site], static fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /** The provider's own diagnosis when there is one; otherwise why it was not sent, in words. */
    private function reason(?string $diagnosis, ?string $suppression): string
    {
        if ($diagnosis !== null && $diagnosis !== '') {
            return $diagnosis;
        }
        $key = match (true) {
            $suppression === ClaimNotificationDelivery::OneOffAttempted => 'client_messages.reason.one_off_attempted',
            in_array($suppression, ['destination_archived', 'recipient_deleted'], true) => 'client_messages.reason.channel_removed',
            // The suppression texts the incident timeline shows ("Monitoring stopped", "Included in the uptime outage" …).
            $suppression !== null && Lang::has('notifications.suppression.'.$suppression) => 'notifications.suppression.'.$suppression,
            default => 'client_messages.reason.not_sent',
        };
        $text = __($key);

        return is_string($text) ? $text : throw new \LogicException('Translations are text.');
    }

    private function iso(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->toIso8601String();
    }
}

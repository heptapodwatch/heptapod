<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Heptapod\Enums\RecipientKind;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Quiet hours and the digest slot of one recipient. The timezone is derived: the user's, the client's or the instance's.
 *
 * @property int $id
 * @property RecipientKind $recipient_kind
 * @property int|null $user_id
 * @property int|null $client_id
 * @property string|null $quiet_start
 * @property string|null $quiet_end
 * @property bool $quiet_weekends
 * @property string $digest_time
 * @property list<int>|null $digest_weekdays
 */
#[Guarded(['id'])]
final class NotificationSchedule extends Model
{
    protected function casts(): array
    {
        return ['recipient_kind' => RecipientKind::class, 'quiet_weekends' => 'boolean', 'digest_weekdays' => 'array'];
    }

    /** The stored schedule of the channel's recipient, or that recipient kind's defaults. */
    public static function forChannel(NotificationDestination $channel): self
    {
        return self::forChannels([$channel])[$channel->id];
    }

    /**
     * Schedules for many channels in one query. The recipient relation already loaded on each channel is reused for
     * the timezone, so routing many channels never loads a user or client per channel.
     *
     * @param  iterable<NotificationDestination>  $channels
     * @return array<int, self> keyed by channel id
     */
    public static function forChannels(iterable $channels): array
    {
        $userIds = [];
        $clientIds = [];
        foreach ($channels as $channel) {
            if ($channel->recipient_kind === RecipientKind::User && $channel->user_id !== null) {
                $userIds[] = $channel->user_id;
            }
            if ($channel->recipient_kind === RecipientKind::Client && $channel->client_id !== null) {
                $clientIds[] = $channel->client_id;
            }
        }
        $query = self::query()->where('recipient_kind', RecipientKind::Team->value);
        $query->getQuery()->when($userIds !== [], static fn (QueryBuilder $stored): QueryBuilder => $stored->orWhereIn('user_id', $userIds))
            ->when($clientIds !== [], static fn (QueryBuilder $stored): QueryBuilder => $stored->orWhereIn('client_id', $clientIds));
        $team = null;
        $byUser = [];
        $byClient = [];
        foreach ($query->get() as $schedule) {
            match ($schedule->recipient_kind) {
                RecipientKind::Team => $team = $schedule,
                RecipientKind::User => $byUser[$schedule->user_id ?? 0] = $schedule,
                RecipientKind::Client => $byClient[$schedule->client_id ?? 0] = $schedule,
            };
        }
        $schedules = [];
        foreach ($channels as $channel) {
            $schedule = match ($channel->recipient_kind) {
                RecipientKind::Team => $team,
                RecipientKind::User => $byUser[$channel->user_id ?? 0] ?? null,
                RecipientKind::Client => $byClient[$channel->client_id ?? 0] ?? null,
            } ?? self::defaults($channel->recipient_kind, $channel->user_id, $channel->client_id);
            if ($channel->relationLoaded('user')) {
                $schedule->setRelation('user', $channel->user);
            }
            if ($channel->relationLoaded('client')) {
                $schedule->setRelation('client', $channel->client);
            }
            $schedules[$channel->id] = $schedule;
        }

        return $schedules;
    }

    /** Team: no quiet hours, daily 08:00 digest. Users and clients: no quiet hours, 08:00 digest on weekdays. */
    public static function defaults(RecipientKind $kind, ?int $userId = null, ?int $clientId = null): self
    {
        return new self(['recipient_kind' => $kind, 'user_id' => $userId, 'client_id' => $clientId,
            'quiet_start' => null, 'quiet_end' => null, 'quiet_weekends' => false, 'digest_time' => '08:00',
            'digest_weekdays' => $kind === RecipientKind::Team ? null : [1, 2, 3, 4, 5]]);
    }

    public function timezone(): string
    {
        return match ($this->recipient_kind) {
            RecipientKind::User => $this->user->timezone ?? app(InstanceSettings::class)->timezone,
            RecipientKind::Client => $this->client->timezone ?? app(InstanceSettings::class)->timezone,
            RecipientKind::Team => app(InstanceSettings::class)->timezone,
        };
    }

    /** @return list<int>|null */
    public function effectiveDigestWeekdays(): ?array
    {
        return $this->quiet_weekends ? array_values(array_intersect($this->digest_weekdays ?? [1, 2, 3, 4, 5, 6, 7], [1, 2, 3, 4, 5]))
            : $this->digest_weekdays;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}

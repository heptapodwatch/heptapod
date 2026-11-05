<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\NotificationDestinationFactory;
use Heptapod\Casts\DestinationSettings;
use Heptapod\Concerns\AuditsConfiguration;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Contracts\Schema\ValidatedConfig;
use Heptapod\Enums\RecipientKind;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Activity;

/**
 * A notification channel: one endpoint owned by the team, a user or a client (design §1.1). The table keeps its name.
 *
 * @property int $id
 * @property RecipientKind $recipient_kind
 * @property int|null $user_id
 * @property int|null $client_id
 * @property int|null $scope_client_id
 * @property bool $account_mail
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removed_reason
 * @property string $label
 * @property string $channel
 * @property string|null $locale
 * @property bool $needs_attention
 * @property string|null $attention_reason
 * @property bool $enabled
 * @property string|null $last_outcome
 * @property string|null $last_outcome_diagnosis
 * @property CarbonImmutable|null $last_outcome_at
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $config_secrets
 * @property array<string, string>|null $default_preferences the grid the channel started with; null for factory-made channels
 */
#[Guarded(['id', 'config_secrets', 'needs_attention', 'attention_reason'])]
#[Hidden(['config_secrets'])]
final class NotificationDestination extends Model
{
    use AuditsConfiguration {
        beforeActivityLogged as private auditConfiguration;
    }

    /** Transient queue-discard metadata accompanies the existing enabled-change audit. */
    public ?int $pauseDiscardedCount = null;

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $this->auditConfiguration($activity, $eventName);
        if ($eventName === 'updated' && $this->pauseDiscardedCount !== null) {
            $activity->properties = ($activity->properties ?? collect())->merge(['suppressed_delivery_count' => $this->pauseDiscardedCount]);
        }
    }

    /** @return list<string> */
    protected function secretAttributes(): array
    {
        return ['config_secrets'];
    }

    /** @return list<string> */
    protected function auditedAttributes(): array
    {
        return ['label', 'channel', 'locale', 'enabled', 'config', 'recipient_kind', 'user_id', 'client_id', 'scope_client_id', 'account_mail',
            'removed_at', 'removed_reason', 'needs_attention', 'attention_reason'];
    }

    /** @use HasFactory<NotificationDestinationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        self::saving(function (self $destination): void {
            if (! $destination->needs_attention) {
                return;
            }
            if ($destination->isDirty('config') || $destination->isDirty('config_secrets')) {
                app(ChannelRegistry::class)->get($destination->channel)->configSchema()->validateConfig($destination->destinationConfig()->values());
                $destination->needs_attention = false;
                $destination->attention_reason = null;
                $destination->enabled = true;
            } else {
                $destination->enabled = false;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'recipient_kind' => RecipientKind::class, 'enabled' => 'boolean', 'needs_attention' => 'boolean', 'account_mail' => 'boolean',
            'config' => DestinationSettings::class, 'config_secrets' => 'encrypted:array', 'last_outcome_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime', 'default_preferences' => 'array',
        ];
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

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'destination_id');
    }

    /** @return HasMany<NotificationPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class, 'destination_id');
    }

    public function destinationConfig(): DestinationConfig
    {
        $config = $this->config;
        if ($this->account_mail && $this->user !== null) {
            // The account address is derived; config.email only holds it after the user is gone (history).
            $config = [...$config, 'email' => $this->user->email];
        }

        return new DestinationConfig($this->id, $this->effectiveLocale(), new ValidatedConfig($config, $this->config_secrets ?? []),
            $this->timezone(), app(InstanceSettings::class)->name, config()->string('app.url'));
    }

    /** Team channels store their language; user and client channels derive it from their recipient. */
    public function effectiveLocale(): string
    {
        $locale = match ($this->recipient_kind) {
            RecipientKind::Team => $this->locale,
            RecipientKind::User => $this->user->locale ?? $this->locale,
            RecipientKind::Client => $this->client->locale ?? $this->locale,
        };

        return in_array($locale, ['en', 'de'], true) ? $locale : 'en';
    }

    public function timezone(): string
    {
        return match ($this->recipient_kind) {
            RecipientKind::User => $this->user->timezone ?? app(InstanceSettings::class)->timezone,
            RecipientKind::Client => $this->client->timezone ?? app(InstanceSettings::class)->timezone,
            RecipientKind::Team => app(InstanceSettings::class)->timezone,
        };
    }

    /**
     * Whether the viewer may see who this channel reaches: its address and its label. Labels can hold addresses (a
     * migrated client contact without a name uses its email as the label), so Members see both only for their own channels.
     */
    public function identityVisibleTo(User $viewer): bool
    {
        return $viewer->hasAnyRole(['Owner', 'Admin']) || ($this->recipient_kind === RecipientKind::User && $this->user_id === $viewer->id);
    }

    /** An account channel is only active while its user's address is verified. Other channels need no verification. */
    public function addressVerified(): bool
    {
        return ! $this->account_mail || ($this->user !== null && $this->user->hasVerifiedEmail());
    }
}

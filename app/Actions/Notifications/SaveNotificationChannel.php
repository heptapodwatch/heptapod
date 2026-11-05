<?php

declare(strict_types=1);

namespace Heptapod\Actions\Notifications;

use Heptapod\Actions\RequestRoutingReconciliation;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\ValidatedConfig;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\User;
use Heptapod\Support\SecretMerge;
use Heptapod\Support\SecretReentry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits one channel of the team, a user or a client (design §7.1). A new channel gets the §1.3 grid in the
 * same transaction. A user's mail is only the account channel; their extra channels use other transports.
 */
final readonly class SaveNotificationChannel
{
    public function __construct(private ChannelRegistry $channels, private RequestRoutingReconciliation $reconcile) {}

    /** @param array<string, mixed> $attributes */
    public function add(RecipientKind $kind, ?User $user, ?Client $client, array $attributes): NotificationDestination
    {
        /** @var array{channel: string, label: string, locale?: string, scope_client_id?: int|null, config: array<string, mixed>} $data */
        $data = Validator::make($attributes, [
            'channel' => ['required', 'string', Rule::in(array_keys($this->channels->all()))],
            'label' => ['required', 'string', 'max:255'],
            'locale' => [$kind === RecipientKind::Team ? 'required' : 'prohibited', Rule::in(['en', 'de'])],
            'scope_client_id' => [$kind === RecipientKind::Team ? 'nullable' : 'prohibited', 'integer', 'exists:clients,id'],
            'config' => ['present', 'array'],
        ], attributes: $this->attributeNames())->validate();
        if (! self::offers($kind, $data['channel'])) {
            throw ValidationException::withMessages(['channel' => __('notifications.user_mail_is_account')]);
        }
        $config = $this->channels->get($data['channel'])->configSchema()->validateConfig($data['config']);

        return NotificationLocks::transaction(function () use ($kind, $user, $client, $data, $config): NotificationDestination {
            $channel = NotificationDestination::query()->create(['recipient_kind' => $kind, 'user_id' => $user?->id, 'client_id' => $client?->id,
                'scope_client_id' => $data['scope_client_id'] ?? null, 'channel' => $data['channel'], 'label' => $data['label'],
                'locale' => $data['locale'] ?? null, 'enabled' => true, 'config' => $config]);
            $role = $user instanceof User ? array_find(['Owner', 'Admin', 'Member'], static fn (string $name): bool => $user->hasRole($name)) : null;
            NotificationPreference::writeInitialGrid($channel->id, NotificationPreference::defaults($kind, $role));
            $this->reconcile->forChannel($channel);

            return $channel;
        });
    }

    /** @return array<string, string> Validation messages name the fields as the channel form labels them. */
    private function attributeNames(): array
    {
        return array_map(static fn (string $key): string => (string) __($key), ['channel' => 'Type', 'label' => 'Name', 'locale' => 'Language',
            'scope_client_id' => 'recipient_settings.scope.label', 'config' => 'recipient_settings.form.settings']);
    }

    /** Whether a new channel of this type may be added for this recipient kind: a user's mail is only the account channel. */
    public static function offers(RecipientKind $kind, string $channelType): bool
    {
        return $kind !== RecipientKind::User || $channelType !== 'mail';
    }

    /**
     * Label, language, team scope and transport settings. Secrets stay when sent back masked or empty; a changed target
     * origin requires the secrets again. A widened team scope can reach open outages (design §2.2).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function edit(NotificationDestination $destination, array $attributes): NotificationDestination
    {
        $team = $destination->recipient_kind === RecipientKind::Team;
        /** @var array{label?: string, locale?: string, scope_client_id?: int|null, config?: array<string, mixed>} $data */
        $data = Validator::make($attributes, [
            'label' => ['sometimes', 'string', 'max:255'],
            'locale' => [$team ? 'sometimes' : 'prohibited', Rule::in(['en', 'de'])],
            'scope_client_id' => [$team ? 'sometimes' : 'prohibited', 'nullable', 'integer', 'exists:clients,id'],
            'config' => [$destination->account_mail ? 'prohibited' : 'sometimes', 'array'],
        ], attributes: $this->attributeNames())->validate();

        return NotificationLocks::transaction(function () use ($destination, $data): NotificationDestination {
            // NotificationLocks: the target client's key (before level 1: the scope's foreign key takes it at the save, and
            // archiving that client holds it exclusive while it waits for the client's incidents), the open episodes a
            // widened scope or a resume marks (incidents), then the channel.
            if (isset($data['scope_client_id'])) {
                NotificationLocks::forShare(DB::table('clients')->where('id', $data['scope_client_id']))->first(['id']);
            }
            if (array_key_exists('scope_client_id', $data) || isset($data['config'])) {
                $this->reconcile->lockEpisodes($destination);
            }
            NotificationLocks::channels([$destination->id]);
            $locked = NotificationDestination::query()->findOrFail($destination->id);
            if ($locked->removed_at !== null) {
                throw ValidationException::withMessages(['channel' => __('notifications.channel_removed')]);
            }
            $widened = array_key_exists('scope_client_id', $data) && $locked->scope_client_id !== null && $data['scope_client_id'] !== $locked->scope_client_id;
            $wasActive = $locked->enabled && ! $locked->needs_attention;
            $locked->fill(array_intersect_key($data, array_flip(['label', 'locale', 'scope_client_id'])));
            if (isset($data['config'])) {
                $locked->setAttribute('config', $this->mergedConfig($locked, $data['config']));
            }
            // Saving repaired settings clears needs_attention and resumes the channel (NotificationDestination::booted):
            // like a resume, it can newly reach open outages (design §2.2).
            $locked->save();
            $resumed = ! $wasActive && $locked->enabled && ! $locked->needs_attention;
            if ($widened || $resumed) {
                $this->reconcile->forChannel($locked->refresh());
            }

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $incoming */
    private function mergedConfig(NotificationDestination $channel, array $incoming): ValidatedConfig
    {
        $schema = $this->channels->get($channel->channel)->configSchema();
        $previous = $channel->destinationConfig()->values();
        $supplied = $incoming;
        foreach ($schema->fields as $name => $field) {
            if (array_key_exists($name, $incoming)) {
                $incoming[$name] = SecretMerge::preserve($field, $incoming[$name] === '' ? null : $incoming[$name], $previous[$name] ?? null, 'config.'.$name);
            }
        }
        /** @var array<string, mixed> $merged */
        $merged = array_replace($previous, $incoming);
        $validated = $schema->validateConfig($merged);
        // Channel URLs (a webhook URL, an ntfy server) can be secrets themselves, so compare every URL-shaped value:
        // a kept secret must never follow the channel to a new origin without being entered again.
        foreach (array_keys($schema->fields) as $name) {
            $before = $previous[$name] ?? null;
            $after = $merged[$name] ?? null;
            if (is_string($before) && is_string($after) && $this->isUrl($before) && $this->isUrl($after)
                && SecretReentry::origin($before) !== SecretReentry::origin($after)) {
                $secrets = array_filter($previous, static fn (string $field): bool => ($schema->fields[$field] ?? null)?->type === FieldType::Secret, ARRAY_FILTER_USE_KEY);
                $errors = SecretReentry::errors($secrets, $supplied, 'config');
                unset($errors['config.'.$name]);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
        }

        return $validated;
    }

    private function isUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}

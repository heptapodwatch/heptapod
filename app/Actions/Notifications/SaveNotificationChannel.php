<?php

declare(strict_types=1);

namespace Heptapod\Actions\Notifications;

use Heptapod\Actions\RequestRoutingReconciliation;
use Heptapod\Actions\SetNotificationDestinationEnabled;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Schema\ValidatedConfig;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
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
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits one channel of the team, a user or a client (design §7.1). A new channel gets the §1.3 grid in the
 * same transaction. A user's mail is only the account channel; their extra channels use other transports.
 */
final readonly class SaveNotificationChannel
{
    public function __construct(private ChannelRegistry $channels, private CheckRegistry $checks, private RequestRoutingReconciliation $reconcile) {}

    /** @param array<string, mixed> $attributes */
    public function add(RecipientKind $kind, ?User $user, ?Client $client, array $attributes): NotificationDestination
    {
        /** @var array{channel: string, label: string, locale?: string, timezone?: string|null, scope_client_id?: int|null, check_types?: list<string>|null, config: array<string, mixed>} $data */
        $data = Validator::make($attributes, [
            'channel' => ['required', 'string', Rule::in(array_keys($this->channels->all()))],
            'label' => ['required', 'string', 'max:255'],
            'locale' => [$kind === RecipientKind::Team ? 'required' : 'prohibited', Rule::in(config()->array('app.supported_locales'))],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'scope_client_id' => [$kind === RecipientKind::Team ? 'nullable' : 'prohibited', 'integer', 'exists:clients,id'],
            'config' => ['present', 'array'],
            ...$this->checkTypeRules(),
        ], attributes: $this->attributeNames())->validate();
        if (! self::offers($kind, $data['channel'])) {
            throw ValidationException::withMessages(['channel' => __('notifications.user_mail_is_account')]);
        }
        $this->assertEnteredWebhookSecretLength($data['channel'], $data['config'], keepsStoredSecret: false);
        $config = $this->channels->get($data['channel'])->configSchema()->validateConfig($data['config']);

        return NotificationLocks::transaction(function () use ($kind, $user, $client, $data, $config): NotificationDestination {
            $role = $user instanceof User ? array_find(['Owner', 'Admin', 'Member'], static fn (string $name): bool => $user->hasRole($name)) : null;

            return $this->create(['recipient_kind' => $kind, 'user_id' => $user?->id, 'client_id' => $client?->id,
                'scope_client_id' => $data['scope_client_id'] ?? null, 'channel' => $data['channel'], 'label' => $data['label'],
                'locale' => $data['locale'] ?? null, 'timezone' => $data['timezone'] ?? null, 'enabled' => true, 'config' => $config, 'check_types' => $data['check_types'] ?? null], NotificationPreference::defaults($kind, $role));
        });
    }

    /**
     * Replace the source settings and routing grid together; a delivery must never see the default grid in between.
     *
     * @param  list<string>  $checks
     * @param  list<NotificationCategory>  $categories
     */
    public function syncOhDear(int $sourceId, ?int $clientId, string $kind, string $label, bool $enabled, array $checks, array $categories, ValidatedConfig $config): NotificationDestination
    {
        return NotificationLocks::transaction(function () use ($sourceId, $clientId, $kind, $label, $enabled, $checks, $categories, $config): NotificationDestination {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['oh-dear-channel:'.$sourceId]);
            $existing = NotificationDestination::query()->where('oh_dear_id', $sourceId)->first();
            if ($clientId !== null && NotificationLocks::forShare(DB::table('clients')->where('id', $clientId)->whereNull('archived_at'))->first(['id']) === null) {
                throw ValidationException::withMessages(['client' => __('import.notice.channel_no_client')]);
            }
            $recipient = $clientId === null ? RecipientKind::Team : RecipientKind::Client;
            if ($existing !== null) {
                $this->reconcile->lockEpisodes(new NotificationDestination(['recipient_kind' => $recipient, 'client_id' => $clientId]));
                NotificationLocks::channels([$existing->id], exclusive: true);
                $existing->refresh();
                if ($existing->removed_at !== null) {
                    throw ValidationException::withMessages(['channel' => __('notifications.channel_removed')]);
                }
                if ($existing->enabled && (! $enabled || $existing->client_id !== $clientId || $existing->channel !== $kind)) {
                    app(SetNotificationDestinationEnabled::class)($existing, false);
                }
            }
            if ($kind === 'webhook') {
                $config = new ValidatedConfig($config->public, [...$config->secrets(),
                    'secret' => $existing?->destinationConfig()->values()['secret'] ?? bin2hex(random_bytes(32))]);
            }
            $grid = array_fill_keys(array_column(NotificationCategory::grid(), 'value'), NotificationMode::Off);
            foreach ($categories as $category) {
                $grid[$category->value] = NotificationMode::Immediate;
            }
            $attributes = ['oh_dear_id' => $sourceId, 'recipient_kind' => $recipient, 'client_id' => $clientId, 'channel' => $kind, 'label' => $label,
                'locale' => $clientId === null ? ($existing->locale ?? app()->getLocale()) : null,
                'scope_client_id' => $clientId === null ? ($existing->scope_client_id ?? null) : null,
                'config' => $config, 'enabled' => $enabled, 'check_types' => $checks];
            if ($existing === null) {
                return $this->create($attributes, $grid);
            }
            $existing->forceFill([...$attributes, 'needs_attention' => false, 'attention_reason' => null])->save();
            NotificationPreference::writeInitialGrid($existing->id, $grid);
            if ($enabled) {
                $this->reconcile->forChannel($existing);
            }

            return $existing;
        });
    }

    /** @param array<string, mixed> $attributes
     * @param  array<string, NotificationMode>  $grid
     */
    private function create(array $attributes, array $grid): NotificationDestination
    {
        $channel = NotificationDestination::query()->create($attributes);
        NotificationPreference::writeInitialGrid($channel->id, $grid);
        if ($channel->enabled) {
            $this->reconcile->forChannel($channel);
        }

        return $channel;
    }

    /** @return array<string, list<string|In>> */
    private function checkTypeRules(): array
    {
        return [
            'check_types' => ['sometimes', 'nullable', 'array', 'list', 'min:1'],
            'check_types.*' => ['required', 'string', 'distinct:strict', Rule::in(array_column($this->checks->keys(), 'value'))],
        ];
    }

    /** @return array<string, string> Validation messages name the fields as the channel form labels them. */
    private function attributeNames(): array
    {
        return array_map(static fn (string $key): string => (string) __($key), ['channel' => 'Type', 'label' => 'Name', 'locale' => 'Language', 'timezone' => 'Timezone',
            'scope_client_id' => 'recipient_settings.scope.label', 'config' => 'recipient_settings.form.settings',
            'check_types' => 'recipient_settings.check_types.label', 'check_types.*' => 'recipient_settings.check_types.label']);
    }

    /** Whether a new channel of this type may be added for this recipient kind: a user's mail is only the account channel. */
    public static function offers(RecipientKind $kind, string $channelType): bool
    {
        return $kind !== RecipientKind::User || $channelType !== 'mail';
    }

    /**
     * Label, language, team scope, check types and transport settings. Secrets stay when sent back masked or empty; a changed target
     * origin requires the secrets again. Widening routing can reach open outages (design §2.2).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function edit(NotificationDestination $destination, array $attributes): NotificationDestination
    {
        $team = $destination->recipient_kind === RecipientKind::Team;
        /** @var array{label?: string, locale?: string, timezone?: string|null, scope_client_id?: int|null, check_types?: list<string>|null, config?: array<string, mixed>} $data */
        $data = Validator::make($attributes, [
            'label' => ['sometimes', 'string', 'max:255'],
            'locale' => [$team ? 'sometimes' : 'prohibited', Rule::in(config()->array('app.supported_locales'))],
            // Every channel may have its own zone; null follows its recipient.
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'scope_client_id' => [$team ? 'sometimes' : 'prohibited', 'nullable', 'integer', 'exists:clients,id'],
            'config' => [$destination->account_mail ? 'prohibited' : 'sometimes', 'array'],
            ...$this->checkTypeRules(),
        ], attributes: $this->attributeNames())->validate();

        return NotificationLocks::transaction(function () use ($destination, $data): NotificationDestination {
            // NotificationLocks: the target client's key (before level 1: the scope's foreign key takes it at the save, and
            // archiving that client holds it exclusive while it waits for the client's incidents), the open episodes a
            // widened route or a resume marks (incidents), then the channel.
            if (isset($data['scope_client_id'])) {
                NotificationLocks::forShare(DB::table('clients')->where('id', $data['scope_client_id']))->first(['id']);
            }
            $allowsUptime = array_key_exists('check_types', $data)
                && ($data['check_types'] === null || in_array(CheckKey::Uptime->value, $data['check_types'], true));
            if (array_key_exists('scope_client_id', $data) || isset($data['config']) || $allowsUptime) {
                $this->reconcile->lockEpisodes($destination);
            }
            NotificationLocks::channels([$destination->id]);
            $locked = NotificationDestination::query()->findOrFail($destination->id);
            if ($locked->removed_at !== null) {
                throw ValidationException::withMessages(['channel' => __('notifications.channel_removed')]);
            }
            $widenedScope = array_key_exists('scope_client_id', $data) && $locked->scope_client_id !== null && $data['scope_client_id'] !== $locked->scope_client_id;
            $addedUptime = $allowsUptime && $locked->check_types !== null && ! in_array(CheckKey::Uptime->value, $locked->check_types, true);
            $wasActive = $locked->enabled && ! $locked->needs_attention;
            $locked->fill(array_intersect_key($data, array_flip(['label', 'locale', 'timezone', 'scope_client_id', 'check_types'])));
            if (isset($data['config'])) {
                $locked->setAttribute('config', $this->mergedConfig($locked, $data['config']));
            }
            // Saving repaired settings clears needs_attention and resumes the channel (NotificationDestination::booted):
            // like a resume, it can newly reach open outages (design §2.2).
            $locked->save();
            $resumed = ! $wasActive && $locked->enabled && ! $locked->needs_attention;
            if ($widenedScope || $addedUptime || $resumed) {
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
        $this->assertEnteredWebhookSecretLength($channel->channel, $incoming, keepsStoredSecret: true);
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
                $errors = SecretReentry::errors($validated->secrets(), $supplied, 'config');
                unset($errors['config.'.$name]);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
        }

        return $validated;
    }

    /**
     * A webhook signing secret must resist offline guessing from one signed delivery. Only a newly entered secret is held to
     * this; on edit a stored shorter one (kept masked or empty) keeps working until it is replaced. A new channel has no
     * stored secret, so the edit form's mask is just a short value there.
     *
     * @param  array<string, mixed>  $config
     */
    private function assertEnteredWebhookSecretLength(string $channel, array $config, bool $keepsStoredSecret): void
    {
        $secret = $config['secret'] ?? null;
        // Empty is left to the schema's required rule.
        $notEntered = $secret === '' || ($keepsStoredSecret && $secret === '********');
        if ($channel === 'webhook' && is_string($secret) && ! $notEntered && mb_strlen(trim($secret), 'UTF-8') < 32) {
            throw ValidationException::withMessages(['values.secret' => __('notifications.webhook_secret_minimum')]);
        }
    }

    private function isUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}

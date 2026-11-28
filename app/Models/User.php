<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Heptapod\Concerns\AuditsConfiguration;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\Role;
use Heptapod\Notifications\PasswordReset;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property ClientScope $client_scope
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $timezone
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $last_seen_at
 * @property CarbonImmutable|null $last_active_at
 * @property CarbonImmutable|null $disabled_at
 */
#[Fillable(['name', 'email', 'password', 'locale', 'timezone', 'client_scope', 'last_seen_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    use AuditsConfiguration;

    /** @return list<string> */
    protected function secretAttributes(): array
    {
        return ['password', 'two_factor_secret', 'two_factor_recovery_codes'];
    }

    protected function auditedAttributes(): array
    {
        return ['name', 'email', 'locale', 'timezone', 'client_scope'];
    }

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** A new account stores its browser's zone; a missing or unknown browser zone stores none, so it follows the instance. */
    public static function initialTimezone(mixed $browserTimezone): ?string
    {
        return is_string($browserTimezone) && in_array($browserTimezone, \DateTimeZone::listIdentifiers(), true) ? $browserTimezone : null;
    }

    /** The user's own zone, or the instance zone while they keep the default. */
    public function resolvedTimezone(): string
    {
        return $this->timezone ?? app(InstanceSettings::class)->timezone;
    }

    /** The viewer's resolved zone, or the instance zone for guests. */
    public static function viewerTimezone(?self $user): string
    {
        return $user instanceof self ? $user->resolvedTimezone() : app(InstanceSettings::class)->timezone;
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] mixed $token): void
    {
        $this->notify(new PasswordReset($token));
    }

    /** @return BelongsToMany<Client, $this> */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class);
    }

    public function isRestrictedMember(): bool
    {
        return $this->client_scope === ClientScope::Restricted && Role::of($this) === Role::Member;
    }

    /** The client an API token is limited to; Sanctum sets a personal access token only on the account it authenticated. */
    public function tokenClientConstraint(): ?int
    {
        $token = $this->currentAccessToken();
        // Sanctum types the token as always set; it is null for sessions and for accounts other than the authenticated one.
        if (! $token instanceof PersonalAccessToken) { // @phpstan-ignore instanceof.alwaysTrue
            return null;
        }
        $clientId = $token->getAttribute('client_id');
        if ($clientId !== null && ! is_int($clientId)) {
            throw new \UnexpectedValueException('Token client constraint must be an integer.');
        }

        return $clientId;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_active_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'client_scope' => ClientScope::class,
        ];
    }
}

<?php

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SiteFactory;
use Heptapod\Concerns\AuditsConfiguration;
use Heptapod\Data\Ui\IconRefreshResultData;
use Heptapod\Enums\AlertReminders;
use Heptapod\Enums\SiteIconBacking;
use Heptapod\Queries\GenericSiteIcons;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelData\Support\EloquentCasts\DataEloquentCast;
use Spatie\Tags\HasTags;
use Spatie\Tags\Tag;

/**
 * @property Collection<int, Tag> $tags
 * @property SiteIconBacking $icon_backing
 * @property string|null $icon_hash
 * @property CarbonImmutable|null $icon_fetched_at
 * @property CarbonImmutable|null $icon_attempted_at
 * @property CarbonImmutable|null $icon_manual_requested_at
 * @property IconRefreshResultData|null $icon_manual_result
 * @property int $id
 * @property int|null $alert_after_minutes
 * @property int|null $alert_recovery_checks
 * @property bool|null $alert_confirms_other_checks
 * @property AlertReminders|null $alert_reminders
 * @property int $client_id
 * @property string $url
 * @property list<array{name: string, value: string}>|null $crawler_headers
 * @property CarbonImmutable|null $paused_by_archive_at
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $resume_at
 * @property CarbonImmutable|null $marked_for_deletion_at
 * @property list<string>|null $send_report_to_emails
 * @property list<string>|null $include_check_types_in_report
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
#[Hidden(['crawler_headers'])]
final class Site extends Model
{
    use AuditsConfiguration {
        beforeActivityLogged as private auditConfiguration;
    }

    /** @var array{origin?: string, reason?: string, resolved_incident_ids?: list<int>, resolved_incident_count?: int} Metadata retained by the deletion audit after dependent incident rows are removed. */
    public array $incidentResolutionAudit = [];

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $this->auditConfiguration($activity, $eventName);
        if ($eventName === 'deleted') {
            $activity->properties = ($activity->properties ?? collect())->merge($this->incidentResolutionAudit);
        }
    }

    private const string DisplayNamePrefix = '^[[:alpha:]][[:alnum:]+.-]*://([^/@?#]*@)?';

    private const string DisplayNameSuffix = '[?#].*$';

    /** @return literal-string */
    public static function displayNameSql(): string
    {
        return "coalesce(nullif(sites.friendly_name, ''), rtrim(regexp_replace(regexp_replace(sites.url, '".self::DisplayNameSuffix."', ''), '".self::DisplayNamePrefix."', ''), '/'))";
    }

    public function displayName(): string
    {
        if ($this->friendly_name !== null && $this->friendly_name !== '') {
            return $this->friendly_name;
        }
        $name = preg_replace(['~'.self::DisplayNameSuffix.'~', '~'.self::DisplayNamePrefix.'~'], '', $this->url);
        if ($name === null) {
            throw new \LogicException('Site display name patterns must be valid.');
        }

        return rtrim($name, '/');
    }

    public function iconUrl(): ?string
    {
        return app(GenericSiteIcons::class)->url($this->id, $this->icon_hash);
    }

    public function iconRefreshEligible(): bool
    {
        $client = $this->client ?? throw new \LogicException('A site must belong to a client.');

        return $this->paused_at === null && $this->marked_for_deletion_at === null
            && $client->archived_at === null;
    }

    /** @return list<string> */
    protected function secretAttributes(): array
    {
        return ['crawler_headers'];
    }

    protected function auditedAttributes(): array
    {
        return ['alert_after_minutes', 'alert_recovery_checks', 'alert_confirms_other_checks', 'alert_reminders', 'client_id', 'url', 'friendly_name', 'group_name', 'description', 'notes', 'send_report_to_emails', 'include_check_types_in_report', 'paused_at', 'resume_at', 'marked_for_deletion_at'];
    }

    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    use HasTags;

    protected function casts(): array
    {
        return ['alert_after_minutes' => 'integer', 'alert_recovery_checks' => 'integer', 'alert_confirms_other_checks' => 'boolean', 'alert_reminders' => AlertReminders::class, 'icon_backing' => SiteIconBacking::class, 'icon_manual_result' => DataEloquentCast::class.':'.IconRefreshResultData::class, 'icon_fetched_at' => 'immutable_datetime', 'icon_attempted_at' => 'immutable_datetime', 'icon_manual_requested_at' => 'immutable_datetime', 'crawler_headers' => 'encrypted:array', 'send_report_to_emails' => 'array', 'include_check_types_in_report' => 'array', 'paused_at' => 'immutable_datetime',
            'paused_by_archive_at' => 'immutable_datetime', 'resume_at' => 'immutable_datetime', 'marked_for_deletion_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<Check, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}

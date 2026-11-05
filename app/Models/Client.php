<?php

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ClientFactory;
use Heptapod\Concerns\AuditsConfiguration;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property CarbonImmutable|null $archived_at
 * @property string|null $short_name
 * @property string|null $contact_name
 * @property string|null $accent_color
 * @property int|null $avatar_site_id
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
final class Client extends Model implements HasMedia
{
    use AuditsConfiguration, InteractsWithMedia;

    public const array AvatarRelations = ['media', 'sites:id,client_id,url,friendly_name,created_at,icon_hash,icon_backing'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile()->useDisk(config()->string('filesystems.default'))->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // GD re-encodes pixels without source metadata; no external optimizer is needed.
        $this->addMediaConversion('avatar')->performOnCollections('logo')->nonOptimized()->nonQueued()->fit(Fit::Crop, 128, 128)->format('png');
    }

    protected function auditedAttributes(): array
    {
        return ['name', 'slug', 'short_name', 'contact_name', 'locale', 'timezone', 'accent_color', 'is_internal', 'archived_at'];
    }

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    /**
     * Assignment and permission pickers retain Internal so it can receive its first site.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function forBrowsing(Builder $query): Builder
    {
        $query->getQuery()->where(static fn (\Illuminate\Database\Query\Builder $clients): \Illuminate\Database\Query\Builder => $clients->where('is_internal', false)
            ->orWhereExists(static fn (\Illuminate\Database\Query\Builder $sites): \Illuminate\Database\Query\Builder => $sites->selectRaw('1')->from('sites')->whereColumn('sites.client_id', 'clients.id')));

        return $query;
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}

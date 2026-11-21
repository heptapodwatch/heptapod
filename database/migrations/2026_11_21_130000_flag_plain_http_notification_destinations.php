<?php

declare(strict_types=1);

use Heptapod\Contracts\Http\DeliveryPrivateHosts;
use Heptapod\Models\NotificationDestination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;

/**
 * ntfy servers and Teams URLs now need HTTPS unless HEPTAPOD_DELIVERY_PRIVATE_HOSTS lists their host. Saved plain-HTTP
 * channels that are on are paused and flagged, never rewritten; saving settings that pass resumes them. A channel the
 * operator already turned off stays off and unflagged: its deliveries are refused at send time if it is turned on again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $urlFields = ['ntfy' => 'server', 'microsoftTeams' => 'url'];
        $plainHttpCandidates = NotificationDestination::query()->where('enabled', true)
            ->where(static fn (Builder $channels): Builder => $channels->where('channel', 'ntfy')->orWhere('channel', 'microsoftTeams'));
        foreach ($plainHttpCandidates->lazyById(100) as $destination) {
            $url = $destination->destinationConfig()->values()[$urlFields[$destination->channel]] ?? null;
            if (is_string($url) && ! DeliveryPrivateHosts::allowsScheme($url)) {
                $destination->forceFill(['enabled' => false, 'needs_attention' => true, 'attention_reason' => 'notifications.needs_https'])->save();
            }
        }
    }

    /** The flag records that the stored address is refused; rolling back the code does not make it safe again. */
    public function down(): void {}
};

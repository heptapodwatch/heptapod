<?php

declare(strict_types=1);

use Heptapod\Contracts\Http\DeliveryPrivateHosts;
use Heptapod\Models\NotificationDestination;
use Illuminate\Database\Migrations\Migration;

/**
 * ntfy servers and Teams URLs now need HTTPS unless HEPTAPOD_DELIVERY_PRIVATE_HOSTS lists their host. Saved plain-HTTP
 * channels are paused and flagged, never rewritten; saving an HTTPS address (or listing the host) resumes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $urlFields = ['ntfy' => 'server', 'microsoftTeams' => 'url'];
        foreach (NotificationDestination::query()->where('channel', 'ntfy')->orWhere('channel', 'microsoftTeams')->lazyById(100) as $destination) {
            $url = $destination->destinationConfig()->values()[$urlFields[$destination->channel]] ?? null;
            if (is_string($url) && ! DeliveryPrivateHosts::allowsScheme($url)) {
                $destination->forceFill(['enabled' => false, 'needs_attention' => true, 'attention_reason' => 'notifications.needs_https'])->save();
            }
        }
    }

    /** The flag records that the stored address is refused; rolling back the code does not make it safe again. */
    public function down(): void {}
};

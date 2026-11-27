<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\SiteServers;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Site;
use Heptapod\Models\User;

/** The Site settings form's Save: the site's own settings and, when the form changed it, the server the site runs on. */
final readonly class SaveSiteSettingsForm
{
    public function __construct(private UpdateSiteSettings $update) {}

    /**
     * One transaction: the server is checked (and held) before anything is written, the link follows the site's own write,
     * which authorizes under its lock, and any failure leaves both unchanged.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $timing
     * @param  SiteServers|null  $servers  set only when the form sent a server
     */
    public function __invoke(User $user, Site $site, array $attributes, array $timing, ?int $clientId, ?SiteServers $servers, ?int $serverId): void
    {
        NotificationLocks::transaction(function () use ($user, $site, $attributes, $timing, $clientId, $servers, $serverId): void {
            $servers?->holdServer($serverId);
            ($this->update)($user, $site, $attributes, $timing, $clientId);
            $servers?->link($site->id, $serverId);
        });
    }
}

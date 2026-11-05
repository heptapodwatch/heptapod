<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Arr;

final readonly class UpdateSiteSettings
{
    public function __construct(private UpdateSite $update, private MoveSiteToClient $move) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $timing
     */
    public function __invoke(User $user, Site $site, array $attributes, array $timing, ?int $clientId): void
    {
        NotificationLocks::transaction(function () use ($site, $attributes, $timing, $clientId, $user): void {
            if ($clientId !== null) {
                $site = ($this->move)($user, $site, $clientId);
            }
            /** @var array<string, mixed> $siteAttributes */
            $siteAttributes = Arr::except($attributes, ['client_id', 'alert_after_minutes', 'alert_recovery_checks']);
            $saved = ($this->update)($site, $siteAttributes);
            if ($timing !== []) {
                $saved->update($timing);
            }
        });
    }
}

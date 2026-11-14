<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final readonly class UpdateSiteSettings
{
    public function __construct(private UpdateSite $update, private MoveSiteToClient $move) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $timing
     */
    public function __invoke(User $user, Site $site, array $attributes, array $timing, ?int $clientId): void
    {
        if (! Gate::forUser($user)->allows('update', $site)) {
            abort_unless($clientId !== null, 403);
            abort_if(Arr::except($attributes, ['client_id']) !== [] || $timing !== [], 403);
        }
        NotificationLocks::transaction(function () use ($site, $attributes, $timing, $clientId, $user): void {
            if ($clientId !== null) {
                $site = ($this->move)($user, $site, $clientId);
            }
            /** @var array<string, mixed> $siteAttributes */
            $siteAttributes = Arr::except($attributes, ['client_id', 'alert_after_minutes', 'alert_recovery_checks', 'alert_confirms_other_checks', 'alert_reminders']);
            if ($siteAttributes === [] && $timing === []) {
                return;
            }
            Gate::forUser($user)->authorize('update', $site);
            $saved = ($this->update)($site, $siteAttributes);
            if ($timing !== []) {
                $saved->update($timing);
            }
        });
    }
}

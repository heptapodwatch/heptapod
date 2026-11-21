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
    public function __construct(private UpdateSite $update, private MoveSiteToClient $move, private AuthorizeSiteWrite $authorize) {}

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
        /** @var array<string, mixed> $siteAttributes */
        $siteAttributes = Arr::except($attributes, ['client_id', 'alert_after_minutes', 'alert_recovery_checks', 'alert_confirms_other_checks', 'alert_reminders']);
        if ($clientId === null) {
            // No move: a guarded write, decided under the shared access lock with the actor as the account is now.
            ($this->authorize)($user, 'update', fn () => $this->save($site, $siteAttributes, $timing), $site);

            return;
        }
        NotificationLocks::transaction(function () use ($site, $siteAttributes, $timing, $clientId, $user): void {
            // The move takes the exclusive access lock first and authorizes itself; the rest is decided under that lock.
            $site = ($this->move)($user, $site, $clientId);
            if ($siteAttributes === [] && $timing === []) {
                return;
            }
            Gate::forUser($this->authorize->currentActor($user))->authorize('update', $site);
            $this->save($site, $siteAttributes, $timing);
        });
    }

    /**
     * @param  array<string, mixed>  $siteAttributes
     * @param  array<string, mixed>  $timing
     */
    private function save(Site $site, array $siteAttributes, array $timing): void
    {
        if ($siteAttributes === [] && $timing === []) {
            return;
        }
        $saved = ($this->update)($site, $siteAttributes);
        if ($timing !== []) {
            $saved->update($timing);
        }
    }
}

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
     * @param  bool  $refuseWhenBusy  the API: a move waits for a structural change only as long as a guarded write, then 409 busy_try_again
     * @param  (\Closure(): void)|null  $extraWrite  a further setting of the same form that another part writes (the server link):
     *                                               an extra setting like the site's own, so a site that takes a move only refuses it,
     *                                               and it runs under the same lock after the site's own write
     */
    public function __invoke(User $user, Site $site, array $attributes, array $timing, ?int $clientId, bool $refuseWhenBusy = false, ?\Closure $extraWrite = null): void
    {
        if (! Gate::forUser($user)->allows('update', $site)) {
            abort_unless($clientId !== null, 403);
            abort_if(Arr::except($attributes, ['client_id']) !== [] || $timing !== [] || $extraWrite instanceof \Closure, 403);
        }
        // Only the timing the caller validated and saves separately leaves the site attributes; UpdateSite rejects any other key.
        /** @var array<string, mixed> $siteAttributes */
        $siteAttributes = Arr::except($attributes, ['client_id', ...array_keys($timing)]);
        if ($clientId === null) {
            // No move: a guarded write, decided under the shared access lock with the actor as the account is now.
            ($this->authorize)($user, 'update', function () use ($site, $siteAttributes, $timing, $extraWrite): void {
                $this->save($site, $siteAttributes, $timing);
                $extraWrite?->__invoke();
            }, $site);

            return;
        }
        NotificationLocks::transaction(function () use ($site, $siteAttributes, $timing, $clientId, $user, $refuseWhenBusy, $extraWrite): void {
            // Level 0 first; the move's own exclusive lock below then re-enters at once.
            $refuseWhenBusy ? NotificationLocks::accessExclusiveOrBusy() : NotificationLocks::accessExclusive();
            $extraSettings = $siteAttributes !== [] || $timing !== [] || $extraWrite instanceof \Closure;
            if ($extraSettings) {
                // The source as it is under the lock: one archived while this request waited takes a move only.
                Gate::forUser($this->authorize->currentActor($user))->authorize('update', Site::query()->findOrFail($site->id));
            }
            $site = ($this->move)($user, $site, $clientId);
            if (! $extraSettings) {
                return;
            }
            Gate::forUser($this->authorize->currentActor($user))->authorize('update', $site);
            $this->save($site, $siteAttributes, $timing);
            $extraWrite?->__invoke();
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

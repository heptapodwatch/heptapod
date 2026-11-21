<?php

declare(strict_types=1);

namespace Heptapod\Actions\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Actions\RequestRoutingReconciliation;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\NotificationTiming;
use Heptapod\Events\Notifications\RoutingContext;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * PUT …/preferences: the channel's grid and site exceptions in one transaction (design §1.3, §7.1). Cells may change
 * only to the modes their category allows. System is not offered to clients or Members and stays on for an Owner's
 * account channel. Turning on a cell that opens uptime episodes or removing a site mute requests reconciliation
 * (§2.2); a Digest cell turned Immediate sends its waiting items on their immediate schedule instead of the next
 * digest.
 */
final readonly class UpdateNotificationPreferences
{
    public const int MaxSiteMutesBatch = 500;

    public function __construct(private RequestRoutingReconciliation $reconcile, private NotificationTiming $timing) {}

    /** @param array<string, mixed> $input */
    public function __invoke(NotificationDestination $destination, array $input, ?User $actor = null): void
    {
        $cells = $input['preferences'] ?? [];
        if (! is_array($cells)) {
            throw ValidationException::withMessages(['preferences' => __('validation.array', ['attribute' => __('recipient_settings.grid.title')])]);
        }
        $requested = [];
        foreach ($cells as $key => $value) {
            $category = is_string($key) ? NotificationCategory::tryFrom($key) : null;
            $mode = is_string($value) ? NotificationMode::tryFrom($value) : null;
            if (! $category instanceof NotificationCategory || ! in_array($category, NotificationCategory::grid(), true)) {
                throw ValidationException::withMessages(['preferences' => __('validation.in', ['attribute' => __('recipient_settings.grid.title')])]);
            }
            if (! $mode instanceof NotificationMode || ($mode === NotificationMode::Digest && ! $category->allowsDigest())) {
                throw ValidationException::withMessages(['preferences.'.$category->value => __('validation.in', ['attribute' => __('recipient_settings.category.'.$category->value.'.label')])]);
            }
            $requested[$category->value] = $mode;
        }

        $muteSiteIds = $this->parseSiteIds($input['mute_site_ids'] ?? [], 'mute_site_ids');
        $unmuteSiteIds = $this->parseSiteIds($input['unmute_site_ids'] ?? [], 'unmute_site_ids');
        if (array_intersect($muteSiteIds, $unmuteSiteIds) !== []) {
            throw ValidationException::withMessages(['site' => __('validation.in', ['attribute' => __('recipient_settings.mutes.title')])]);
        }
        if ($requested === [] && $muteSiteIds === [] && $unmuteSiteIds === []) {
            throw ValidationException::withMessages(['preferences' => __('validation.required', ['attribute' => __('recipient_settings.grid.title')])]);
        }
        $allSiteIds = array_values(array_unique([...$muteSiteIds, ...$unmuteSiteIds]));
        if (count($allSiteIds) > self::MaxSiteMutesBatch) {
            throw ValidationException::withMessages(['site' => __('validation.max.array', ['attribute' => __('recipient_settings.mutes.title'), 'max' => self::MaxSiteMutesBatch])]);
        }
        $muteActor = null;
        if ($allSiteIds !== []) {
            if (! $actor instanceof User) {
                throw new \LogicException('Updating site mutes requires an authenticated actor.');
            }
            $muteActor = $actor;
        }

        NotificationLocks::transaction(function () use ($destination, $requested, $muteSiteIds, $unmuteSiteIds, $allSiteIds, $muteActor): void {
            // NotificationLocks: actor (level 1), sites (level 6), open episodes (level 7), channel (level 8), deliveries (level 9).
            if ($muteActor instanceof User) {
                NotificationLocks::users([$muteActor->id]);
                NotificationLocks::sites($allSiteIds);
                $sites = Site::query()->whereKey($allSiteIds)->get()->keyBy('id');
                foreach ($allSiteIds as $siteId) {
                    $site = $sites->get($siteId);
                    if (! $site instanceof Site || Gate::forUser($muteActor)->denies('view', $site)) {
                        abort(404);
                    }
                    if ($destination->recipient_kind === RecipientKind::Client && $site->client_id !== $destination->client_id) {
                        throw ValidationException::withMessages(['site' => __('notifications.mute_other_client')]);
                    }
                }
            }
            $openingRequested = array_any(
                RequestRoutingReconciliation::OpeningCategories,
                static fn (NotificationCategory $category): bool => ($requested[$category->value] ?? NotificationMode::Off) !== NotificationMode::Off,
            );
            if ($openingRequested) {
                $this->reconcile->lockEpisodes($destination);
            } elseif ($unmuteSiteIds !== []) {
                $this->reconcile->lockEpisodes($destination, $unmuteSiteIds);
            }
            NotificationLocks::channels([$destination->id]);
            $locked = NotificationDestination::query()->with('user')->findOrFail($destination->id);
            if ($locked->removed_at !== null) {
                throw ValidationException::withMessages(['preferences' => __('notifications.channel_removed')]);
            }
            $system = $requested[NotificationCategory::System->value] ?? null;
            if ($system instanceof NotificationMode && ! $this->systemEditable($locked)) {
                throw ValidationException::withMessages(['preferences.system' => __('notifications.system_not_editable')]);
            }

            $opened = false;
            $nowImmediate = [];
            if ($requested !== []) {
                /** @var array<string, string> $current */
                $current = DB::table('notification_preferences')->where('destination_id', $locked->id)->pluck('mode', 'category')->all();
                foreach ($requested as $category => $mode) {
                    $before = NotificationMode::from($current[$category] ?? throw new \LogicException('Notification grid row missing for channel '.$locked->id.' and '.$category.'.'));
                    if ($before === $mode) {
                        continue;
                    }
                    DB::table('notification_preferences')->where('destination_id', $locked->id)->where('category', $category)->update(['mode' => $mode->value]);
                    $opened = $opened || ($before === NotificationMode::Off && in_array(NotificationCategory::from($category), RequestRoutingReconciliation::OpeningCategories, true));
                    if ($before === NotificationMode::Digest && $mode === NotificationMode::Immediate) {
                        $nowImmediate[] = $category;
                    }
                }
                activity('audit')->performedOn($locked)->event('notification.preferences_updated')
                    ->withProperties(['before' => $current, 'after' => array_map(static fn (NotificationMode $mode): string => $mode->value, $requested)])
                    ->log('notification.preferences_updated');
            }

            $unmutedSiteIds = [];
            if ($muteActor instanceof User) {
                foreach ($muteSiteIds as $siteId) {
                    $changed = DB::table('notification_site_mutes')->insertOrIgnore([
                        'destination_id' => $locked->id,
                        'site_id' => $siteId,
                        'actor_id' => $muteActor->id,
                        'created_at' => now(),
                    ]);
                    if ($changed > 0) {
                        activity('audit')->causedBy($muteActor)->performedOn($locked)->event('notification.site_muted')
                            ->withProperties(['site_id' => $siteId])->log('notification.site_muted');
                    }
                }
                foreach ($unmuteSiteIds as $siteId) {
                    $changed = DB::table('notification_site_mutes')->where('destination_id', $locked->id)->where('site_id', $siteId)->delete();
                    if ($changed > 0) {
                        $unmutedSiteIds[] = $siteId;
                        activity('audit')->causedBy($muteActor)->performedOn($locked)->event('notification.site_unmuted')
                            ->withProperties(['site_id' => $siteId])->log('notification.site_unmuted');
                    }
                }
            }

            if ($opened) {
                $this->reconcile->forChannel($locked);
            } else {
                foreach ($unmutedSiteIds as $siteId) {
                    $this->reconcile->forChannel($locked, $siteId);
                }
            }
            if ($nowImmediate !== []) {
                $this->releaseDigestItems($locked, $nowImmediate);
            }
        });
    }

    /** @return list<int> */
    private function parseSiteIds(mixed $raw, string $field): array
    {
        if (! is_array($raw)) {
            throw ValidationException::withMessages([$field => __('validation.array', ['attribute' => __('recipient_settings.mutes.title')])]);
        }
        if (count($raw) > self::MaxSiteMutesBatch) {
            throw ValidationException::withMessages([$field => __('validation.max.array', ['attribute' => __('recipient_settings.mutes.title'), 'max' => self::MaxSiteMutesBatch])]);
        }
        $ids = [];
        foreach ($raw as $value) {
            if (! is_int($value) || $value < 1) {
                throw ValidationException::withMessages([$field => __('validation.integer', ['attribute' => __('recipient_settings.mutes.title')])]);
            }
            $ids[$value] = $value;
        }

        return array_values($ids);
    }

    /** Owners and Admins may hear system issues; an Owner's account channel always does (§3.4). Clients and Members never. */
    private function systemEditable(NotificationDestination $channel): bool
    {
        return match ($channel->recipient_kind) {
            RecipientKind::Team => true,
            RecipientKind::Client => false,
            RecipientKind::User => $channel->user !== null && $channel->user->hasAnyRole(['Owner', 'Admin'])
                && (! $channel->account_mail || ! $channel->user->hasRole('Owner')),
        };
    }

    /**
     * Items that wait for this channel's next digest in a category that is now Immediate leave the digest and get the
     * time planning would give them now: due at once, or after quiet hours unless critical.
     *
     * @param  list<string>  $categories
     */
    private function releaseDigestItems(NotificationDestination $channel, array $categories): void
    {
        $at = CarbonImmutable::now('UTC');
        $schedule = NotificationSchedule::forChannel($channel);
        $waiting = Delivery::query()->with(['event.site', 'event.check', 'event.incident.check'])->where('destination_id', $channel->id)
            ->where('state', DeliveryState::Pending->value)->where('digest', true)->where('suppressed', false);
        $waiting->getQuery()->whereIn('category', $categories)->whereNull('digest_event_id');
        // NotificationLocks: the channel is held by the caller; its deliveries follow in ascending id.
        NotificationLocks::deliveries((clone $waiting)->pluck('id'));
        $eventIds = [];
        foreach ($waiting->lazyById(100) as $delivery) {
            $event = $delivery->event ?? throw new \LogicException('A digest item must have an event.');
            $context = RoutingContext::fromEvent($event);
            $due = $context instanceof RoutingContext ? $this->timing->schedule($context, $schedule, $at, false)['due'] : $at;
            $delivery->update(['digest' => false, 'next_attempt_at' => $due]);
            $eventIds[] = $event->id;
        }
        Delivery::announceEvents($eventIds);
    }
}

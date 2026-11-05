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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PUT …/preferences: the channel's grid (design §1.3, §7.1). Cells may change only to the modes their category allows.
 * System is not offered to clients or Members and stays on for an Owner's account channel. Turning on a cell that opens
 * uptime episodes requests reconciliation (§2.2); a Digest cell turned Immediate sends its waiting items on their
 * immediate schedule instead of the next digest.
 */
final readonly class UpdateNotificationPreferences
{
    public function __construct(private RequestRoutingReconciliation $reconcile, private NotificationTiming $timing) {}

    /** @param array<string, mixed> $input */
    public function __invoke(NotificationDestination $destination, array $input): void
    {
        $cells = $input['preferences'] ?? null;
        if (! is_array($cells) || $cells === []) {
            throw ValidationException::withMessages(['preferences' => __('validation.required', ['attribute' => __('recipient_settings.grid.title')])]);
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

        NotificationLocks::transaction(function () use ($destination, $requested): void {
            // NotificationLocks: the open episodes a newly opened cell marks (incidents), then the channel.
            if (array_any(RequestRoutingReconciliation::OpeningCategories, static fn (NotificationCategory $category): bool => ($requested[$category->value] ?? NotificationMode::Off) !== NotificationMode::Off)) {
                $this->reconcile->lockEpisodes($destination);
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
            /** @var array<string, string> $current */
            $current = DB::table('notification_preferences')->where('destination_id', $locked->id)->pluck('mode', 'category')->all();
            $opened = false;
            $nowImmediate = [];
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
            if ($opened) {
                $this->reconcile->forChannel($locked);
            }
            if ($nowImmediate !== []) {
                $this->releaseDigestItems($locked, $nowImmediate);
            }
            activity('audit')->performedOn($locked)->event('notification.preferences_updated')
                ->withProperties(['before' => $current, 'after' => array_map(static fn (NotificationMode $mode): string => $mode->value, $requested)])
                ->log('notification.preferences_updated');
        });
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

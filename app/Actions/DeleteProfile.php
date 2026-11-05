<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Auth\MemberAccessGuard;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DeleteProfile
{
    public function __construct(private MemberAccessGuard $guard, private RevokeMemberAccess $revoke, private ArchiveNotificationChannel $archive) {}

    public function __invoke(User $user): void
    {
        NotificationLocks::transaction(function () use ($user): void {
            $this->guard->lockOwners();
            // NotificationLocks: the user (exclusive), then every row its deletion writes by referential action, level by
            // level: the incidents it authored a note on (SET NULL), all its channels (SET NULL; the archive below holds
            // them exclusive), and in one ascending set the deliveries the archives write and those naming it as actor
            // (SET NULL). Leaf tables are left to the cascade (see NotificationLocks).
            NotificationLocks::users([$user->id], exclusive: true);
            NotificationLocks::incidents(DB::table('incidents')->where('note_author_user_id', $user->id)->pluck('id'));
            $channelIds = DB::table('notification_destinations')->where('user_id', $user->id)->pluck('id');
            NotificationLocks::channels($channelIds, exclusive: true);
            NotificationLocks::deliveriesMatching(DB::table('deliveries')->select('id')->whereIn('destination_id', $channelIds)
                ->where('suppressed', false)->where(static function (Builder $archived): void {
                    $archived->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value, DeliveryState::Sending->value])
                        ->orWhere(static fn (Builder $recurring): Builder => $recurring->where('digest', true)->whereNull('digest_recurrence_ended_at'));
                })
                ->union(DB::table('deliveries')->select('id')->where('actor_id', $user->id)));
            $current = User::query()->findOrFail($user->id);
            if ($current->hasRole('Owner') && $this->guard->activeOwnerCount() <= 1) {
                throw ValidationException::withMessages(['password' => __('profile.delete_last_owner')]);
            }
            ($this->revoke)($current);
            // Before the delete nulls user_id: queued work is suppressed and history keeps the account address.
            $channels = NotificationDestination::query()->where('user_id', $current->id);
            $channels->getQuery()->whereNull('removed_at')->orderBy('id');
            foreach ($channels->get() as $channel) {
                ($this->archive)($channel, ArchiveNotificationChannel::RecipientDeleted, $current);
            }
            $current->delete();
        });
    }
}

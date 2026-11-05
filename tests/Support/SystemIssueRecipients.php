<?php

declare(strict_types=1);

use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * System issue recipients as production creates them: a user with a role and the account channel every user has.
 *
 * @param  array<string, mixed>  $attributes
 */
function systemIssueRecipient(string $role, bool $systemOn = false, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);
    $channel = app(EnsureAccountChannel::class)($user);
    if ($systemOn) {
        systemIssueCell($channel, NotificationMode::Immediate);
    }

    return $user;
}

function systemIssueCell(NotificationDestination $channel, NotificationMode $mode): void
{
    DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', NotificationCategory::System->value)->update(['mode' => $mode->value]);
}

function systemIssueAccountChannel(User $user): NotificationDestination
{
    return NotificationDestination::query()->where('user_id', $user->id)->where('account_mail', true)->sole();
}

/** The ledger rows of one issue phase ('open' or 'recovery'). */
function systemIssueDeliveries(int $issueId, string $phase): Builder
{
    return DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')
        ->where('events.system_issue_id', $issueId)->where('events.type', $phase === 'open' ? 'system.issue_opened' : 'system.issue_resolved')
        ->select('deliveries.*');
}

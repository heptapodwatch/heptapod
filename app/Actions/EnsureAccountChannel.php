<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\Schema\ValidatedConfig;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every user has one account-email channel. Its address, language and timezone come from the account, so its
 * stored config stays empty while the user exists. New users get the design §1.3 defaults; an existing channel keeps its grid.
 */
final readonly class EnsureAccountChannel
{
    public function __construct(private RequestRoutingReconciliation $reconcile) {}

    /** @param bool $silent Alert categories off (System per role): a first Owner joining preconfigured routing. */
    public function __invoke(User $user, bool $firstOwner = false, bool $silent = false): NotificationDestination
    {
        return DB::transaction(function () use ($user, $firstOwner, $silent): NotificationDestination {
            $existing = NotificationDestination::query()->where('user_id', $user->id)->where('account_mail', true)->first();
            if ($existing instanceof NotificationDestination) {
                return $existing;
            }
            $role = array_find(['Owner', 'Admin', 'Member'], static fn (string $name): bool => $user->hasRole($name));
            $channel = NotificationDestination::query()->create(['recipient_kind' => RecipientKind::User, 'user_id' => $user->id,
                'account_mail' => true, 'channel' => 'mail', 'label' => $user->name, 'enabled' => true, 'locale' => null,
                'config' => new ValidatedConfig([], [])]);
            $cells = NotificationPreference::defaults(RecipientKind::User, $role, $firstOwner);
            if ($silent) {
                $cells = [...array_map(static fn (): NotificationMode => NotificationMode::Off, $cells), 'system' => $cells['system']];
            }
            NotificationPreference::writeInitialGrid($channel->id, $cells);
            $this->reconcile->forChannel($channel);

            return $channel;
        });
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Policies;

use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class SitePolicy
{
    public function view(User $user, Site $site): Response
    {
        return $this->accessible($user, $site->client_id);
    }

    public function update(User $user, Site $site): Response
    {
        return $this->writable($user, $site->client_id);
    }

    public function delete(User $user, Site $site): Response
    {
        return $this->writable($user, $site->client_id);
    }

    public function create(User $user, int $clientId): Response
    {
        return $this->writable($user, $clientId);
    }

    private function writable(User $user, int $clientId): Response
    {
        $access = $this->accessible($user, $clientId);
        if ($access->denied()) {
            return $access;
        }

        if (! $user->hasAnyRole(['Owner', 'Admin'])) {
            return Response::deny();
        }

        $clients = Client::query();
        $clients->getQuery()->where('id', $clientId)->whereNull('archived_at');

        return $clients->getQuery()->exists() ? Response::allow() : Response::deny();
    }

    /** @return Builder<Site> */
    public function visibleSites(User $user, bool $includeArchived = false): Builder
    {
        abort_unless($user->disabled_at === null && $user->hasAnyRole(['Owner', 'Admin', 'Member']), 403);
        $query = Site::query();
        $query->getQuery()->select('sites.*');
        if (! $includeArchived) {
            $archived = Client::query();
            $archived->getQuery()->select('id')->whereNotNull('archived_at');
            $query->getQuery()->whereNotIn('sites.client_id', $archived->getQuery());
        }
        if ($user->isRestrictedMember()) {
            $query->getQuery()->whereIntegerInRaw('sites.client_id', $this->restrictedClientIds($user));
        }
        if (($constraint = $user->tokenClientConstraint()) !== null) {
            $query->getQuery()->where('sites.client_id', $constraint);
        }

        return $query;
    }

    /** @return list<int> */
    public function visibleSiteIds(User $user): array
    {
        /** @var list<int> $ids */
        $ids = $this->visibleSites($user)->getQuery()->select('sites.id')->pluck('sites.id')->all();

        return $ids;
    }

    /** @return list<int> */
    public function activeVisibleSiteIds(User $user): array
    {
        /** @var list<int> $ids */
        $ids = $this->visibleSites($user)->getQuery()
            ->whereNull('sites.paused_at')->whereNull('sites.marked_for_deletion_at')->pluck('sites.id')->all();

        return $ids;
    }

    /** @return Builder<Client> */
    public function visibleClients(User $user, bool $includeArchived = false): Builder
    {
        abort_unless($user->disabled_at === null && $user->hasAnyRole(['Owner', 'Admin', 'Member']), 403);
        $clients = Client::query();
        $clients->getQuery()->select('clients.*');
        if (! $includeArchived) {
            $clients->getQuery()->whereNull('clients.archived_at');
        }
        if ($user->isRestrictedMember()) {
            $clients->getQuery()->whereIntegerInRaw('clients.id', $this->restrictedClientIds($user));
        }
        if (($constraint = $user->tokenClientConstraint()) !== null) {
            $clients->getQuery()->where('clients.id', $constraint);
        }

        return $clients;
    }

    /**
     * Membership identifiers are valid for one HTTP request. Future member/scope actions that change membership
     * within a request must call forgetMembership($user).
     *
     * @return list<int>
     */
    private function restrictedClientIds(User $user): array
    {
        $request = request();
        if ($request->route() === null) {
            return $this->readMembership($user);
        }

        $key = self::class.'.membership.'.$user->id;
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->readMembership($user));
        }
        /** @var list<int> $ids */
        $ids = $request->attributes->get($key);

        return $ids;
    }

    public function forgetMembership(User $user): void
    {
        request()->attributes->remove(self::class.'.membership.'.$user->id);
    }

    /** @return list<int> */
    private function readMembership(User $user): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('client_user')->where('user_id', $user->id)->pluck('client_id')->all();

        return $ids;
    }

    /** Persisted-user access shared by the policy and batched notification followers. */
    public function viewableUsers(QueryBuilder $query, string $users, int|Expression $clientId): QueryBuilder
    {
        $roles = static fn (QueryBuilder $roles, array $names): QueryBuilder => $roles->selectRaw('1')
            ->from('model_has_roles as site_access_role')->join('roles', 'roles.id', '=', 'site_access_role.role_id')
            ->whereColumn('site_access_role.model_id', $users.'.id')->where('site_access_role.model_type', (new User)->getMorphClass())
            ->whereIn('roles.name', $names);

        return $query->whereNull($users.'.disabled_at')->where(function (QueryBuilder $access) use ($users, $clientId, $roles): void {
            $access->whereExists(fn (QueryBuilder $q): QueryBuilder => $roles($q, ['Owner', 'Admin']))
                ->orWhere(function (QueryBuilder $members) use ($users, $clientId, $roles): void {
                    $members->whereExists(fn (QueryBuilder $q): QueryBuilder => $roles($q, ['Member']))
                        ->where(fn (QueryBuilder $scope): QueryBuilder => $scope->where($users.'.client_scope', 'all')
                            ->orWhereExists(fn (QueryBuilder $membership): QueryBuilder => $membership->selectRaw('1')->from('client_user')
                                ->whereColumn('client_user.user_id', $users.'.id')->where('client_user.client_id', $clientId)));
                });
        });
    }

    private function accessible(User $user, int $clientId): Response
    {
        $constraint = $user->tokenClientConstraint();
        if ($constraint !== null && $constraint !== $clientId) {
            return Response::denyAsNotFound();
        }
        $users = DB::table('users')->where('users.id', $user->id);

        return $this->viewableUsers($users, 'users', $clientId)->exists() ? Response::allow() : Response::denyAsNotFound();
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Auth\MemberAccessGuard;
use Heptapod\Enums\ClientScope;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class ChangeMemberAccess
{
    public function __construct(private MemberAccessGuard $guard, private RevokeMemberAccess $revoke) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(User $actor, User $member, array $attributes): void
    {
        /** @var array{role: string, client_ids?: list<int>} $data */
        $data = Validator::make($attributes, [
            'role' => ['required', Rule::in($actor->hasRole('Owner') ? ['Owner', 'Admin', 'Member'] : ['Member'])],
            'client_ids' => [Rule::requiredIf(($attributes['role'] ?? null) === 'Member'), 'array',
                Rule::when(($attributes['role'] ?? null) === 'Member', ['min:1'], ['size:0'])],
            'client_ids.*' => ['integer', 'distinct', Rule::exists('clients', 'id')],
        ], [], [
            'role' => __('Role'), 'client_ids' => __('Client selection'), 'client_ids.*' => __('Client'),
        ])->validate();
        DB::transaction(function () use ($actor, $member, $data): void {
            $this->guard->lockOwners();
            $currentActor = User::query()->findOrFail($actor->id);
            $query = User::query()->whereKey($member->id);
            NotificationLocks::forUpdate($query->getQuery());
            $current = $query->firstOrFail();
            abort_if($current->disabled_at !== null, 404);
            abort_if($currentActor->disabled_at !== null, 403);
            $this->guard->authorize($currentActor, $current);
            abort_if($data['role'] !== 'Member' && ! $currentActor->hasRole('Owner'), 403);
            if ($current->hasRole('Owner') && $data['role'] !== 'Owner') {
                $this->guard->protectLastOwner();
            }
            $roleChanged = ! $current->hasRole($data['role']);
            $before = $current->clients()->toBase()->pluck('clients.id')->all();
            $after = $data['client_ids'] ?? [];
            sort($before);
            sort($after);
            if ($roleChanged) {
                $current->syncRoles([$data['role']]);
            }
            $scope = $data['role'] === 'Member' ? ClientScope::Restricted : ClientScope::All;
            $scopeChanged = $current->client_scope !== $scope;
            if ($scopeChanged) {
                $current->update(['client_scope' => $scope]);
            }
            if ($before !== $after) {
                $current->clients()->sync($after);
            }
            if ($roleChanged || $scopeChanged || $before !== $after) {
                ($this->revoke)($current);
            }
        });
    }
}

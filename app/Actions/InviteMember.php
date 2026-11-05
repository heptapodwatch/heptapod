<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Enums\ClientScope;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Heptapod\Notifications\TeamInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InviteMember
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(User $actor, array $attributes): Invitation
    {
        abort_unless($actor->hasAnyRole(['Owner', 'Admin']), 403);
        /** @var array{email: string, role: string, client_ids?: list<int>} $data */
        $data = Validator::make($attributes, [
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::in($actor->hasRole('Owner') ? ['Admin', 'Member'] : ['Member'])],
            'client_ids' => [Rule::requiredIf(($attributes['role'] ?? null) === 'Member'), 'array',
                Rule::when(($attributes['role'] ?? null) === 'Member', ['min:1'], ['size:0'])],
            'client_ids.*' => ['integer', 'distinct', Rule::exists('clients', 'id')],
        ], [], [
            'email' => __('Email'), 'role' => __('Role'),
            'client_ids' => __('Client selection'), 'client_ids.*' => __('Client'),
        ])->validate();
        $email = Str::lower(trim($data['email']));
        $existing = User::query()->where('email', $email)->first();
        if (($existing !== null && ($existing->disabled_at === null || (! $actor->hasRole('Owner') && ! $existing->hasRole('Member'))))
            || Invitation::query()->where('email', $email)->getQuery()->exists()) {
            throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => __('Email')])]);
        }
        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(static fn (): Invitation => Invitation::query()->create([
            'email' => $email, 'token_hash' => hash('sha256', $token), 'role' => $data['role'],
            'client_scope' => $data['role'] === 'Member' ? ClientScope::Restricted->value : ClientScope::All->value,
            'client_ids' => $data['client_ids'] ?? [], 'inviter_id' => $actor->id, 'expires_at' => now()->addDays(7),
        ]));
        Notification::route('mail', $email)->notify(new TeamInvitation($invitation, route('invitations.show', ['token' => $token])));

        return $invitation;
    }
}

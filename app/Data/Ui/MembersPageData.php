<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MembersPageData extends Data
{
    /**
     * @param  list<array{id: int, name: string, email: string, role: string|null, client_ids: list<int>, client_scope: string, effective_client_scope: string, last_active_at: string|null, disabled: bool, is_last_owner: bool, can_edit: bool, can_deactivate: bool, can_reinvite: bool, allowed_roles: list<string>}>  $members
     * @param  list<array{id: int, email: string, role: string, client_ids: list<int>, client_scope: string, inviter: string|null, expires_at: string, expired: bool, can_manage: bool}>  $invitations
     * @param  list<array{id: int, name: string, archived: bool, short_name: string|null, accent_color: string|null, avatar: ClientAvatarData, is_internal: bool}>  $clients
     * @param  list<string>  $invite_roles
     */
    public function __construct(
        public readonly array $members,
        public readonly array $invitations,
        public readonly array $clients,
        public readonly array $invite_roles,
    ) {}
}

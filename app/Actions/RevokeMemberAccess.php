<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Jobs\TerminateLiveConnections;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Support\ImportTokenStore;
use Illuminate\Support\Facades\DB;

final readonly class RevokeMemberAccess
{
    public function __construct(private SitePolicy $sites) {}

    public function __invoke(User $member): void
    {
        DB::table('sessions')->where('user_id', $member->id)->delete();
        $member->tokens()->getQuery()->delete();
        ImportTokenStore::forgetUser($member->id);
        $this->sites->forgetMembership($member);
        DB::afterCommit(static fn (): mixed => dispatch_sync(new TerminateLiveConnections($member->id)));
    }
}

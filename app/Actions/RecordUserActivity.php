<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RecordUserActivity
{
    public function __invoke(User $user): void
    {
        $now = now('UTC');
        if ($user->last_active_at !== null && $user->last_active_at->greaterThan($now->copy()->subMinute())) {
            return;
        }
        DB::table('users')->where('id', $user->id)->whereNull('disabled_at')
            ->where(fn (Builder $query): Builder => $query->whereNull('last_active_at')->orWhere('last_active_at', '<=', $now->copy()->subMinute()))
            ->update(['last_active_at' => $now]);
        $user->last_active_at = $now;
    }
}

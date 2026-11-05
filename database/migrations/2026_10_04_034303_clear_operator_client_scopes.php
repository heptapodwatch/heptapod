<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $operators = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('roles.name', ['Owner', 'Admin'])->select('model_has_roles.model_id');
        DB::table('users')->whereIn('id', $operators)->update(['client_scope' => 'all']);
        DB::table('client_user')->whereIn('user_id', $operators)->delete();
    }

    public function down(): void
    {
        // Removed operator memberships cannot be reconstructed; no destructive rollback.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['Owner', 'Admin', 'Member'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function down(): void
    {
        // Reference roles and existing user assignments survive rollback.
    }
};

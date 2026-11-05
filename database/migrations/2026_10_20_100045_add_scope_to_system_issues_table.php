<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing issues share the empty scope; disk issues use one scope per measured path.
        Schema::table('system_issues', function (Blueprint $table): void {
            $table->text('scope')->default('');
        });
        DB::statement('CREATE UNIQUE INDEX system_issues_one_open_per_scope ON system_issues (kind, scope) WHERE resolved_at IS NULL');
        DB::statement('DROP INDEX system_issues_one_open_per_kind');
    }

    public function down(): never
    {
        throw new LogicException('Forward-only: removing scopes would merge independent disk issues. Restore the pre-upgrade backup instead.');
    }
};

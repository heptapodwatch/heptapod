<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand-only: NULL means "not pending", so existing rows need no backfill and the previous image ignores the column. */
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->string('pending_reason', 64)->nullable();
        });
        DB::statement("ALTER TABLE checks ADD CONSTRAINT checks_pending_reason CHECK (pending_reason IN ('dns_accept_recheck'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE checks DROP CONSTRAINT checks_pending_reason');
        Schema::table('checks', function (Blueprint $table): void {
            $table->dropColumn('pending_reason');
        });
    }
};

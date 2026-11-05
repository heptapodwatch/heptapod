<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_state_check');
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_state_check CHECK (state IN ('pending', 'sending', 'sent', 'failed', 'logged'))");
    }

    public function down(): void
    {
        // Never relabel a locally logged message as delivered or make it retryable on rollback.
        if (DB::table('deliveries')->where('state', 'logged')->exists()) {
            throw new RuntimeException('Cannot remove the logged delivery state while logged deliveries exist.');
        }
        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_state_check');
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_state_check CHECK (state IN ('pending', 'sending', 'sent', 'failed'))");
    }
};

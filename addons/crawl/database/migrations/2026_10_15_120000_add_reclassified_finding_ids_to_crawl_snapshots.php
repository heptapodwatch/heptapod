<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Identities a policy reclassification removed from the count; they stay known so a return to Broken is not "new". */
    public function up(): void
    {
        Schema::table('crawl_snapshots', function (Blueprint $table): void {
            $table->jsonb('reclassified_finding_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_snapshots', function (Blueprint $table): void {
            $table->dropColumn('reclassified_finding_ids');
        });
    }
};

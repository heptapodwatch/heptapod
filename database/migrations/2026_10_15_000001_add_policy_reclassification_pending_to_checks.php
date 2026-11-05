<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Set by heptapod:reclassify-links when an accepted Warning still rests on relabeled findings; consumed by the next complete evaluation. */
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->jsonb('policy_reclassification_pending')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->dropColumn('policy_reclassification_pending');
        });
    }
};

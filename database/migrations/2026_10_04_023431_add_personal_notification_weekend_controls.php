<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->boolean('quiet_weekends')->default(false);
            $table->jsonb('digest_weekdays')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn(['quiet_weekends', 'digest_weekdays']);
        });
    }
};

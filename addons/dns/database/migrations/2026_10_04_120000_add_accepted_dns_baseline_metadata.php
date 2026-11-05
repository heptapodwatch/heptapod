<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_observations', function (Blueprint $table): void {
            $table->boolean('auto_accept_changes')->default(false);
        });
        Schema::table('dns_baselines', function (Blueprint $table): void {
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accepted_by_name')->nullable();
            $table->timestampTz('accepted_at', 6)->nullable();
            $table->boolean('accepted_pending_recheck')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dns_observations', function (Blueprint $table): void {
            $table->dropColumn('auto_accept_changes');
        });
        Schema::table('dns_baselines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn(['accepted_by_name', 'accepted_at', 'accepted_pending_recheck']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', static function (Blueprint $table): void {
            $table->index(['site_id', 'first_failure_at'], 'incidents_site_failure_at');
        });
        Schema::table('sites', static function (Blueprint $table): void {
            $table->index(['client_id', 'id'], 'sites_client_id_id');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', static function (Blueprint $table): void {
            $table->dropIndex('incidents_site_failure_at');
        });
        Schema::table('sites', static function (Blueprint $table): void {
            $table->dropIndex('sites_client_id_id');
        });
    }
};

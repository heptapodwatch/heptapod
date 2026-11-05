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
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('short_name', 8)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->timestampTz('archived_at', 6)->nullable();
        });
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_accent_color_hex CHECK (accent_color IS NULL OR accent_color ~ '^#[0-9A-Fa-f]{6}$')");
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_internal_not_archived CHECK (NOT is_internal OR archived_at IS NULL)');
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['short_name', 'contact_name', 'accent_color', 'archived_at']);
        });
    }
};

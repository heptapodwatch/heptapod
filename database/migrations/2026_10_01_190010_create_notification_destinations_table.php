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
        Schema::create('notification_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->string('channel');
            $table->string('locale', 2)->default('en');
            $table->boolean('enabled')->default(true);
            $table->string('disabled_reason')->nullable();
            $table->jsonb('config');
            $table->text('config_secrets')->nullable();
            $table->jsonb('notification_types')->nullable();
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE notification_destinations ADD CONSTRAINT destination_config_object CHECK (jsonb_typeof(config) = 'object')");
        DB::statement("ALTER TABLE notification_destinations ADD CONSTRAINT destination_locale CHECK (locale IN ('en', 'de'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_destinations');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_destinations ALTER COLUMN locale TYPE varchar(10)');
        DB::statement("ALTER TABLE notification_destinations ADD CONSTRAINT destination_locale_format CHECK (locale ~ '^[a-z]{2}(-[A-Z]{2})?$') NOT VALID");
        DB::statement('ALTER TABLE notification_destinations VALIDATE CONSTRAINT destination_locale_format');
        DB::statement('ALTER TABLE notification_destinations DROP CONSTRAINT destination_locale');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE notification_destinations ADD CONSTRAINT destination_locale CHECK (locale IN ('en', 'de'))");
        DB::statement('ALTER TABLE notification_destinations DROP CONSTRAINT destination_locale_format');
        DB::statement('ALTER TABLE notification_destinations ALTER COLUMN locale TYPE varchar(2)');
    }
};

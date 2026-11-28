<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A site marked for deletion stays paused (PauseSite writes both), so "paused_at IS NULL" alone means the site is monitored. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_deletion_requires_pause CHECK (marked_for_deletion_at IS NULL OR paused_at IS NOT NULL) NOT VALID');
        DB::statement('ALTER TABLE sites VALIDATE CONSTRAINT sites_deletion_requires_pause');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP CONSTRAINT sites_deletion_requires_pause');
    }
};

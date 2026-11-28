<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A site marked for deletion stays paused (PauseSite writes both), so "paused_at IS NULL" alone means the site is monitored.
 * Each statement commits on its own: ADD ... NOT VALID holds ACCESS EXCLUSIVE only briefly, and VALIDATE scans under
 * SHARE UPDATE EXCLUSIVE, which leaves reads and writes running. Dropping first makes a rerun after a failed VALIDATE safe.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_deletion_requires_pause');
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_deletion_requires_pause CHECK (marked_for_deletion_at IS NULL OR paused_at IS NOT NULL) NOT VALID');
        DB::statement('ALTER TABLE sites VALIDATE CONSTRAINT sites_deletion_requires_pause');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_deletion_requires_pause');
    }
};

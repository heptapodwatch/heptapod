<?php

declare(strict_types=1);

use Heptapod\Actions\BackfillAvatarBacking;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Each batch converges independently; file conversions cannot be rolled back by a database transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        app(BackfillAvatarBacking::class)();
    }

    public function down(): void
    {
        // Derived PNGs and classifications remain valid on rollback; original uploads are unchanged.
    }
};

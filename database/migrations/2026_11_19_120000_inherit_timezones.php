<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user's, a client's and a channel's zone are their own choice or empty; empty inherits (channel → its user or
 * client → the instance). Every install ran on the instance default UTC, so a stored UTC was never a choice: it
 * becomes empty and behaviour stays the same. The client contact name left with the contacts' move to channels.
 * Every step converges, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'clients'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN timezone DROP NOT NULL, ALTER COLUMN timezone DROP DEFAULT");
            DB::table($table)->where('timezone', 'UTC')->update(['timezone' => null]);
        }
        if (Schema::hasColumn('clients', 'contact_name')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->dropColumn('contact_name');
            });
        }
        if (! Schema::hasColumn('notification_destinations', 'timezone')) {
            Schema::table('notification_destinations', function (Blueprint $table): void {
                $table->string('timezone')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('contact_name')->nullable();
        });
        foreach (['users', 'clients'] as $table) {
            DB::table($table)->whereNull('timezone')->update(['timezone' => 'UTC']);
            DB::statement("ALTER TABLE {$table} ALTER COLUMN timezone SET DEFAULT 'UTC', ALTER COLUMN timezone SET NOT NULL");
        }
    }
};

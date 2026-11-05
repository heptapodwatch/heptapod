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
        Schema::table('incidents', function (Blueprint $table): void {
            $table->timestampTz('routing_reconcile_requested_at', 6)->nullable();
        });
        DB::statement('CREATE INDEX IF NOT EXISTS incidents_routing_reconcile_requested ON incidents (id) WHERE routing_reconcile_requested_at IS NOT NULL');
    }

    public function down(): void {}
};

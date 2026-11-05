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
        DB::transaction(function (): void {
            $ackEvents = DB::table('events')->where('type', 'incident.acknowledged')->select('id');
            DB::table('deliveries')->whereIn('digest_event_id', $ackEvents)->update(['digest_event_id' => null]);
            DB::table('deliveries')->whereIn('event_id', $ackEvents)->delete();
            DB::table('events')->where('type', 'incident.acknowledged')->delete();
            Schema::table('incidents', function (Blueprint $table): void {
                $table->dropColumn(['acknowledged_at', 'acknowledged_by']);
            });
        });
    }

    public function down(): void
    {
        // Rolling back restores nullable columns, not deleted acknowledgement events or deliveries.
        Schema::table('incidents', function (Blueprint $table): void {
            $table->timestampTz('acknowledged_at', 6)->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->timestampTz('snoozed_until', 6)->nullable();
            $table->foreignId('note_author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('note_updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn(['snoozed_until', 'note_author_user_id', 'note_updated_at']);
        });
    }
};

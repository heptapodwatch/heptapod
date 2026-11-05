<?php

declare(strict_types=1);

use Heptapod\Contracts\Schema\SecretMinimum;
use Heptapod\Models\NotificationDestination;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notification_destinations', 'needs_attention')) {
            Schema::table('notification_destinations', function (Blueprint $table): void {
                $table->boolean('needs_attention')->default(false);
                $table->string('attention_reason')->nullable();
            });
        }
        foreach (NotificationDestination::query()->lazyById(100) as $destination) {
            if (array_any(Arr::flatten($destination->config_secrets ?? []), SecretMinimum::tooShort(...))) {
                $destination->forceFill(['enabled' => false, 'needs_attention' => true, 'attention_reason' => 'notifications.secret_too_short'])->save();
            }
        }
    }

    public function down(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn(['needs_attention', 'attention_reason']);
        });
    }
};

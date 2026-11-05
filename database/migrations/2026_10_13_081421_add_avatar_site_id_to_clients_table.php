<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', static function (Blueprint $table): void {
            // Missing/moved sites fall through in the resolver; no site-to-client delete cascade.
            $table->unsignedBigInteger('avatar_site_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', static function (Blueprint $table): void {
            $table->dropColumn('avatar_site_id');
        });
    }
};

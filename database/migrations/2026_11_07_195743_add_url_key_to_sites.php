<?php

declare(strict_types=1);

use Heptapod\Actions\NormaliseSiteUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('url_key', 64)->nullable()->unique();
        });
        $normalise = new NormaliseSiteUrl;
        $seen = [];
        DB::table('sites')->select('id', 'url')->orderBy('id')->chunkById(500, function ($sites) use ($normalise, &$seen): void {
            foreach ($sites as $site) {
                /** @var stdClass&object{id: int, url: string} $site */
                try {
                    $key = $normalise->key($site->url);
                } catch (ValidationException) {
                    // Invalid legacy addresses stay untouched and do not prevent upgrades.
                    continue;
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                DB::table('sites')->where('id', $site->id)->update(['url_key' => $key]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('url_key');
        });
    }
};

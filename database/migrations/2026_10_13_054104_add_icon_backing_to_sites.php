<?php

use Heptapod\Actions\ClassifySiteIconBacking;
use Heptapod\Actions\FetchSiteIcon;
use Heptapod\Enums\SiteIconBacking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('icon_backing', 32)->default(SiteIconBacking::None->value);
        });
        $classify = new ClassifySiteIconBacking;
        DB::table('sites')->whereNotNull('icon_hash')
            ->select(['id', 'icon_hash'])->orderBy('id')->chunkById(100, function (Collection $sites) use ($classify): void {
                /** @var Collection<int, stdClass&object{id: int, icon_hash: string}> $sites */
                foreach ($sites as $site) {
                    $stream = Storage::disk()->readStream('site-icons/'.$site->id.'/'.$site->icon_hash.'.png');
                    if (! is_resource($stream)) {
                        throw new RuntimeException('Stored site icon missing for site '.$site->id.'.');
                    }
                    try {
                        $png = stream_get_contents($stream, FetchSiteIcon::ByteCap + 1);
                        if ($png === false) {
                            throw new RuntimeException('Cannot read stored site icon for site '.$site->id.'.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    DB::table('sites')->where('id', $site->id)->where('icon_hash', $site->icon_hash)
                        ->update(['icon_backing' => $classify($png)->value]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('icon_backing');
        });
    }
};

<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\FileManipulator;

final readonly class BackfillAvatarBacking
{
    public function __construct(private ClassifySiteIconBacking $classify, private FileManipulator $conversions) {}

    /** @return array{sites_scanned: int, site_backings_changed: int, client_avatars_regenerated: int} */
    public function __invoke(): array
    {
        $sites = 0;
        $changed = 0;
        $query = Site::query();
        $query->getQuery()->whereNotNull('icon_hash');
        $query->chunkById(100, function (Collection $batch) use (&$sites, &$changed): void {
            foreach ($batch as $site) {
                $hash = $site->icon_hash;
                $png = Storage::disk()->get('site-icons/'.$site->id.'/'.$hash.'.png') ?? throw new \RuntimeException('Stored site icon is missing.');
                $backing = ($this->classify)($png);
                // A concurrent refresh must not receive the classification of the previous image.
                $changed += Site::query()->whereKey($site->id)->where('icon_hash', $hash)->where('icon_backing', '!=', $backing->value)->update(['icon_backing' => $backing]);
                $sites++;
            }
        });
        $clients = 0;
        Client::query()->with('media')->whereHas('media')->chunkById(100, function (Collection $batch) use (&$clients): void {
            foreach ($batch as $client) {
                foreach ($client->getMedia('logo') as $media) {
                    // The conversion event stores both the normalized hash and the shared ink classification.
                    $this->conversions->createDerivedFiles($media, ['avatar']);
                    $clients++;
                }
            }
        });

        return ['sites_scanned' => $sites, 'site_backings_changed' => $changed, 'client_avatars_regenerated' => $clients];
    }
}

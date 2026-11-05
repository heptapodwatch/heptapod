<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\BackfillAvatarBacking as Backfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Reclassify stored site icons and regenerate normalized client avatars in bounded batches')]
#[Signature('heptapod:backfill-avatar-backing')]
final class BackfillAvatarBacking extends Command
{
    public function handle(Backfill $backfill): int
    {
        $result = $backfill();
        $this->info('Site icons scanned: '.$result['sites_scanned'].'; backing values changed: '.$result['site_backings_changed'].'; client avatars regenerated: '.$result['client_avatars_regenerated'].'.');

        return self::SUCCESS;
    }
}

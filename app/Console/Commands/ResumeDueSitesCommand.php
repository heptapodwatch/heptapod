<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\ResumeSite;
use Heptapod\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('heptapod:resume-due-sites')]
#[Description('Resume sites when timed pauses expire')]
final class ResumeDueSitesCommand extends Command
{
    public function handle(ResumeSite $resume): int
    {
        $query = Site::query();
        $query->getQuery()->whereNotNull('paused_at')->where('resume_at', '<=', now());
        $query->chunkById(100, static function (Collection $sites) use ($resume): void {
            foreach ($sites as $site) {
                $resume($site, onlyIfDue: true);
            }
        });

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Jobs\RefreshSiteIcon;
use Heptapod\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

#[Description('Queue weekly icon refreshes for active sites')]
#[Signature('heptapod:refresh-site-icons')]
final class RefreshSiteIcons extends Command
{
    public function handle(): int
    {
        $sites = Site::query()->whereHas('client', static function (Builder $query): void {
            $query->getQuery()->whereNull('archived_at');
        });
        $sites->getQuery()->whereNull('paused_at')->whereNull('marked_for_deletion_at')
            ->where(static fn (QueryBuilder $query): QueryBuilder => $query->whereNull('icon_attempted_at')->orWhere('icon_attempted_at', '<=', now()->subWeek()));
        $sites->chunkById(100, static function (Collection $sites): void {
            foreach ($sites as $site) {
                RefreshSiteIcon::dispatch($site->id);
            }
        });

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Heptapod\Actions\ClassifySiteIconBacking;
use Heptapod\Actions\FetchSiteIcon;
use Heptapod\Data\Ui\IconRefreshResultData;
use Heptapod\Exceptions\IconRefreshFailed;
use Heptapod\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Timeout(60)]
#[Tries(5)]
final class RefreshSiteIcon implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $siteId, public bool $manual = false)
    {
        $this->onQueue('icons');
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [new WithoutOverlapping('site-icon:'.$this->siteId)->releaseAfter(60)->expireAfter(120)];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Site icon job failed', ['site_id' => $this->siteId, 'exception' => $exception]);
        if ($this->manual) {
            Site::query()->find($this->siteId)?->forceFill([
                'icon_manual_result' => new IconRefreshResultData($exception instanceof TimeoutExceededException ? 'icons.timeout' : 'icons.worker'),
            ])->save();
        }
    }

    public function handle(FetchSiteIcon $fetch, ClassifySiteIconBacking $classify): void
    {
        $site = Site::query()->with('client')->find($this->siteId);
        if ($site === null) {
            return;
        }
        if (! $site->iconRefreshEligible()) {
            if ($this->manual) {
                $site->forceFill(['icon_manual_result' => new IconRefreshResultData('icons.unavailable')])->save();
            }

            return;
        }
        if (! $this->manual && $site->icon_attempted_at?->greaterThan(now()->subWeek()) === true) {
            return;
        }
        $site->forceFill(['icon_attempted_at' => now()])->save();
        $failureReason = 'icons.decode';
        try {
            $png = $fetch($site);
            $backing = $classify($png);
            $failureReason = 'icons.storage';
            $hash = hash('sha256', $png);
            Storage::disk()->put('site-icons/'.$site->id.'/'.$hash.'.png', $png);
            $replacedHash = DB::transaction(function () use ($site, $hash, $backing): ?string {
                $query = Site::query();
                $query->getQuery()->lockForUpdate();
                $current = $query->find($site->id);
                if ($current === null || $current->url !== $site->url) {
                    return null;
                }
                if (! $current->iconRefreshEligible()) {
                    if ($this->manual) {
                        $current->forceFill(['icon_manual_result' => new IconRefreshResultData('icons.unavailable')])->save();
                    }

                    return null;
                }
                $previousHash = $current->icon_hash;
                $current->forceFill(['icon_hash' => $hash, 'icon_backing' => $backing, 'icon_fetched_at' => now(),
                    ...($this->manual ? ['icon_manual_result' => new IconRefreshResultData('icons.refreshed')] : [])])->save();

                return $previousHash !== $hash ? $previousHash : null;
            });
            if ($replacedHash !== null) {
                Storage::disk()->delete('site-icons/'.$site->id.'/'.$replacedHash.'.png');
            }
        } catch (\Throwable $exception) {
            // Icon failure is diagnostic only: preserve the old icon and never enter monitoring/incident recording.
            $result = $exception instanceof IconRefreshFailed ? $exception->result : new IconRefreshResultData($failureReason);
            Log::warning('Site icon refresh failed', ['site_id' => $site->id, 'result' => $result->toArray(), 'exception' => $exception]);
            if ($this->manual) {
                Site::query()->whereKey($site->id)->where('url', $site->url)->first()?->forceFill([
                    'icon_manual_result' => $result,
                ])->save();
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Illuminate\Support\Facades\DB;

final class ResetDnsBaseline
{
    public function __invoke(int $checkId): void
    {
        DB::transaction(function () use ($checkId): void {
            DB::table('dns_baseline_generations')->insertOrIgnore(['check_id' => $checkId, 'generation' => 0]);
            DB::table('dns_baseline_generations')->where('check_id', $checkId)->increment('generation');
            DB::table('dns_baselines')->where('check_id', $checkId)->delete();
        });
    }
}

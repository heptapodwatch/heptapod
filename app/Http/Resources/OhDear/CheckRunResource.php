<?php

declare(strict_types=1);

namespace Heptapod\Http\Resources\OhDear;

use Heptapod\Models\CheckRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CheckRunResource extends JsonResource
{
    #[\Override]
    public static $wrap;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CheckRun $run */
        $run = $this->resource;

        return ['id' => $run->id, 'check_id' => $run->check_id, 'check_type' => $run->check_type->value, 'result' => $run->result->ohDearValue(),
            'started_at' => $run->started_at?->utc()->toIso8601String(), 'ended_at' => $run->ended_at->utc()->toIso8601String(), 'ohdear_url' => url('/checks/'.$run->check_id.'/runs/'.$run->id)];
    }
}

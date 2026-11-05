<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\CheckEnablement;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Check;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class EnableCheck
{
    public function __construct(private CheckRegistry $registry, private CheckEnablement $enablement) {}

    public function __invoke(Check $check): void
    {
        if (! in_array($check->type, $this->registry->keys(), true)) {
            throw ValidationException::withMessages(['type' => __('api.unsupported')]);
        }
        DB::transaction(function () use ($check): void {
            if ($check->exists) {
                $query = Check::query()->whereKey($check->id);
                $query->getQuery()->lockForUpdate();
                $current = $query->firstOrFail();
                $check->setRawAttributes($current->getAttributes(), true);
            }
            $wasDisabled = $check->exists && ! $check->enabled;
            $this->enablement->enable($check, $this->registry->get($check->type)->definition());
            if ($wasDisabled && $check->type === CheckKey::Uptime) {
                DB::table('unmonitored_periods')->where('check_id', $check->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            }
        });
    }
}

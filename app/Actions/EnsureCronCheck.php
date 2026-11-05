<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\Site;

final readonly class EnsureCronCheck
{
    public function __construct(private UpdateSite $update, private EnableCheck $enable) {}

    public function __invoke(Site $site): Check
    {
        ($this->update)($site, ['cron_check_settings' => []]);
        $check = $site->checks()->getQuery()->where('type', CheckKey::Cron)->firstOrFail();
        ($this->enable)($check);

        return $check;
    }
}

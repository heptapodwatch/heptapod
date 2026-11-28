<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Heptapod\Contracts\ServersSwitch;
use Spatie\LaravelSettings\Settings;

final class ServersSettings extends Settings implements ServersSwitch
{
    // The Owner's switch in Settings → General.
    public bool $enabled;

    public static function group(): string
    {
        return 'servers';
    }

    public function isOn(): bool
    {
        return $this->enabled;
    }

    public function turn(bool $on): void
    {
        $this->enabled = $on;
        $this->save();
    }
}

<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

use Heptapod\Addons\Servers\Data\ServerThresholdsData;
use Heptapod\Addons\Servers\Server;

final class UpdateServerSettings
{
    /**
     * Saves the name and keeps only the thresholds that differ from the instance defaults, so a server on the defaults
     * follows a later change of them; a mount's percent and duration are kept each on its own. An ignored mount keeps no
     * threshold.
     *
     * @param  array{name: string, mounts: list<array{mount: string, percent: int|numeric-string, minutes: int|numeric-string}>, ignored_mounts: list<string>, memory_percent: int|numeric-string, memory_minutes: int|numeric-string, load_per_core: int|float|numeric-string, load_minutes: int|numeric-string, no_report_minutes: int|numeric-string}  $values  validated (ServersController::update), numbers possibly as text
     */
    public function __invoke(Server $server, array $values): void
    {
        $defaults = ServerThresholdsData::defaults();
        $ignored = array_values(array_unique($values['ignored_mounts']));
        sort($ignored);
        $mounts = [];
        foreach ($values['mounts'] as $mount) {
            $custom = array_filter(['percent' => (int) $mount['percent'], 'minutes' => (int) $mount['minutes']],
                static fn (int $value, string $key): bool => $value !== $defaults->{'disk_'.$key}, ARRAY_FILTER_USE_BOTH);
            if ($custom !== [] && ! in_array($mount['mount'], $ignored, true)) {
                $mounts[] = ['mount' => $mount['mount'], ...$custom];
            }
        }
        usort($mounts, static fn (array $a, array $b): int => strcmp($a['mount'], $b['mount']));
        $settings = array_filter([
            'memory_percent' => (int) $values['memory_percent'], 'memory_minutes' => (int) $values['memory_minutes'],
            'load_per_core' => (float) $values['load_per_core'], 'load_minutes' => (int) $values['load_minutes'],
            'no_report_minutes' => (int) $values['no_report_minutes'],
        ], static fn (int|float $value, string $key): bool => $value !== $defaults->{$key}, ARRAY_FILTER_USE_BOTH);
        if ($mounts !== []) {
            $settings['mounts'] = $mounts;
        }
        if ($ignored !== []) {
            $settings['ignored_mounts'] = $ignored;
        }
        $server->forceFill(['name' => $values['name'], 'settings' => $settings])->save();
    }
}

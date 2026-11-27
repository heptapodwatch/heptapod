<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Illuminate\Support\Facades\DB;

final class RecordServerReport
{
    /**
     * Stores one validated agent report; false when its sample_id is already stored (a retry), which is not a new report.
     *
     * @param  array{sample_id: string, agent: array{version: string, os: string, arch: string, distro: string, kernel: string}, host: array{hostname: string, uptime_seconds: int, reboot_required: bool|null, ips: list<string>}, cpu: array{cores: int, used_percent: int|float}, load: array{1: int|float, 5: int|float, 15: int|float}, memory: array{total_bytes: int, available_bytes: int}, filesystems: list<array{mount: string, total_bytes: int, free_bytes: int}>, updates: array{pending: int|null, security: int|null}}  $report
     */
    public function __invoke(Server $server, array $report, CarbonImmutable $observedAt, CarbonImmutable $receivedAt): bool
    {
        // Unknown keys never reach storage: the metrics are rebuilt from the validated fields only. Hostname, IPs and the
        // agent facts describe the server and live on its row.
        $metrics = [
            'cpu' => ['cores' => $report['cpu']['cores'], 'used_percent' => $report['cpu']['used_percent']],
            'load' => ['1' => $report['load'][1], '5' => $report['load'][5], '15' => $report['load'][15]],
            'memory' => ['total_bytes' => $report['memory']['total_bytes'], 'available_bytes' => $report['memory']['available_bytes']],
            'filesystems' => array_map(static fn (array $filesystem): array => ['mount' => $filesystem['mount'],
                'total_bytes' => $filesystem['total_bytes'], 'free_bytes' => $filesystem['free_bytes']], $report['filesystems']),
            'host' => ['uptime_seconds' => $report['host']['uptime_seconds'], 'reboot_required' => $report['host']['reboot_required']],
            'updates' => ['pending' => $report['updates']['pending'], 'security' => $report['updates']['security']],
        ];

        return DB::transaction(static function () use ($server, $report, $metrics, $observedAt, $receivedAt): bool {
            $stored = DB::table('server_samples')->insertOrIgnore([
                'server_id' => $server->id, 'sample_id' => strtolower($report['sample_id']),
                'observed_at' => $observedAt->utc()->format('Y-m-d H:i:s.uP'), 'received_at' => $receivedAt->utc()->format('Y-m-d H:i:s.uP'),
                'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),
            ]) === 1;
            if ($stored) {
                $server->forceFill(['last_report_at' => $receivedAt, 'agent_version' => $report['agent']['version'], 'agent_os' => $report['agent']['os'],
                    'agent_arch' => $report['agent']['arch'], 'agent_distro' => $report['agent']['distro'], 'agent_kernel' => $report['agent']['kernel'],
                    'hostname' => $report['host']['hostname'], 'ips' => $report['host']['ips']])->save();
            }

            return $stored;
        });
    }
}

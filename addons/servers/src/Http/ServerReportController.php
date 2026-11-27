<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** POST /agent/v1/report: one agent sample, exactly per the ingest contract v1. */
final class ServerReportController
{
    private const int MaxBodyBytes = 16 * 1024;

    private const int StaleMinutes = 10;

    private const int FutureMinutes = 2;

    public function __invoke(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MaxBodyBytes) {
            return response()->json(['message' => 'The report is larger than 16 KiB.'], 413);
        }
        $token = ServerToken::parse($request->bearerToken());
        if (! $token instanceof ServerToken) {
            return response()->json(['message' => 'A valid agent token is required.'], 401);
        }
        $server = Server::query()->where('public_id', $token->publicId)->first();
        if ($server === null) {
            return response()->json(['message' => 'This server was removed.'], 404);
        }
        if (! $token->matches($server->token_hash)) {
            return response()->json(['message' => 'A valid agent token is required.'], 401);
        }

        $validator = Validator::make($request->json()->all(), $this->rules());
        if ($validator->fails()) {
            return response()->json(['message' => 'The report is invalid.', 'errors' => $validator->errors()], 422);
        }
        /** @var array{sample_id: string, observed_at: string, agent: array{version: string, os: string, arch: string, distro: string, kernel: string}, host: array{hostname: string, uptime_seconds: int, reboot_required: bool|null, ips: list<string>}, cpu: array{cores: int, used_percent: int|float}, load: array{1: int|float, 5: int|float, 15: int|float}, memory: array{total_bytes: int, available_bytes: int}, filesystems: list<array{mount: string, total_bytes: int, free_bytes: int}>, updates: array{pending: int|null, security: int|null}} $report */
        $report = $validator->validated();
        $now = CarbonImmutable::now();
        $observedAt = CarbonImmutable::parse($report['observed_at']);
        if ($observedAt->lt($now->subMinutes(self::StaleMinutes)) || $observedAt->gt($now->addMinutes(self::FutureMinutes))) {
            return response()->json(['message' => 'observed_at must lie between 10 minutes ago and 2 minutes ahead.',
                'errors' => ['observed_at' => ['Out of the accepted window.']]], 422);
        }

        // Unknown keys never reach storage: the metrics are rebuilt from the validated fields only.
        $metrics = [
            'cpu' => ['cores' => $report['cpu']['cores'], 'used_percent' => $report['cpu']['used_percent']],
            'load' => ['1' => $report['load'][1], '5' => $report['load'][5], '15' => $report['load'][15]],
            'memory' => ['total_bytes' => $report['memory']['total_bytes'], 'available_bytes' => $report['memory']['available_bytes']],
            'filesystems' => array_map(static fn (array $filesystem): array => ['mount' => $filesystem['mount'],
                'total_bytes' => $filesystem['total_bytes'], 'free_bytes' => $filesystem['free_bytes']], $report['filesystems']),
            'host' => ['uptime_seconds' => $report['host']['uptime_seconds'], 'reboot_required' => $report['host']['reboot_required']],
            'updates' => ['pending' => $report['updates']['pending'], 'security' => $report['updates']['security']],
        ];
        $stored = DB::transaction(static function () use ($server, $report, $metrics, $observedAt, $now): bool {
            $stored = DB::table('server_samples')->insertOrIgnore([
                'server_id' => $server->id, 'sample_id' => strtolower($report['sample_id']),
                'observed_at' => $observedAt->utc()->format('Y-m-d H:i:s.uP'), 'received_at' => $now->utc()->format('Y-m-d H:i:s.uP'), 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),
            ]) === 1;
            // A retried duplicate is already stored and does not count as a new report.
            if ($stored) {
                $server->forceFill(['last_report_at' => $now, 'agent_version' => $report['agent']['version'], 'agent_os' => $report['agent']['os'],
                    'agent_arch' => $report['agent']['arch'], 'agent_distro' => $report['agent']['distro'], 'agent_kernel' => $report['agent']['kernel'],
                    'hostname' => $report['host']['hostname'], 'ips' => $report['host']['ips']])->save();
            }

            return $stored;
        });

        return response()->json(['interval_seconds' => config()->integer('servers.interval_seconds')], $stored ? 202 : 200);
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        $nonNegativeInteger = ['required', 'integer:strict', 'min:0'];
        $load = ['required', 'numeric:strict', 'min:0'];
        $count = ['present', 'nullable', 'integer:strict', 'min:0'];

        return [
            'sample_id' => ['required', 'string', 'uuid'],
            // RFC 3339 in UTC, as Go's time.RFC3339(Nano) writes it.
            'observed_at' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?Z$/D', 'date'],
            'agent' => ['required', 'array'],
            'agent.version' => ['required', 'string', 'max:32'],
            'agent.os' => ['required', 'string', 'max:32'],
            'agent.arch' => ['required', 'string', 'max:32'],
            'agent.distro' => ['required', 'string', 'max:64'],
            'agent.kernel' => ['required', 'string', 'max:128'],
            'host' => ['required', 'array'],
            'host.hostname' => ['required', 'string', 'max:253'],
            'host.uptime_seconds' => $nonNegativeInteger,
            'host.reboot_required' => ['present', 'nullable', 'boolean:strict'],
            'host.ips' => ['present', 'array', 'list', 'max:16'],
            'host.ips.*' => ['required', 'string', 'ip'],
            'cpu' => ['required', 'array'],
            'cpu.cores' => ['required', 'integer:strict', 'min:1', 'max:4096'],
            'cpu.used_percent' => ['required', 'numeric:strict', 'min:0', 'max:100'],
            'load' => ['required', 'array'],
            'load.1' => $load,
            'load.5' => $load,
            'load.15' => $load,
            'memory' => ['required', 'array'],
            'memory.total_bytes' => $nonNegativeInteger,
            'memory.available_bytes' => $nonNegativeInteger,
            'filesystems' => ['present', 'array', 'list', 'max:16'],
            'filesystems.*' => ['required', 'array'],
            'filesystems.*.mount' => ['required', 'string', 'max:256', 'starts_with:/'],
            'filesystems.*.total_bytes' => $nonNegativeInteger,
            'filesystems.*.free_bytes' => $nonNegativeInteger,
            'updates' => ['required', 'array'],
            'updates.pending' => $count,
            'updates.security' => $count,
        ];
    }
}

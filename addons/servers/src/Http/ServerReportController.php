<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Actions\RecordServerReport;
use Heptapod\Addons\Servers\Actions\ServerReportOutcome;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/** POST /agent/v1/report: one agent sample, exactly per the ingest contract v1. Refusals are AgentResponses words. */
final class ServerReportController
{
    /** The web server refuses larger bodies before PHP reads them (request_body in the Caddyfiles); this is the second line. */
    private const int MaxBodyBytes = 16 * 1024;

    private const int StaleMinutes = 10;

    private const int FutureMinutes = 2;

    private const int SecondsPerReport = 20;

    /** Strings the agent sends are single-line text; NUL and other control characters never reach PostgreSQL. */
    private const string Text = 'not_regex:/[\x00-\x1F\x7F]/';

    public function __invoke(Request $request, RecordServerReport $record): JsonResponse
    {
        if (strlen($request->getContent()) > self::MaxBodyBytes) {
            return AgentResponses::error(413);
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return AgentResponses::error(415);
        }
        $token = ServerToken::parse($request->bearerToken());
        if (! $token instanceof ServerToken) {
            return AgentResponses::error(401);
        }
        $server = Server::query()->where('public_id', $token->publicId)->first();
        if ($server === null) {
            return AgentResponses::error(404);
        }
        if (! $token->matches($server->token_hash)) {
            return AgentResponses::error(401);
        }
        // After authentication and keyed by the server: a wrong or rotated-out token cannot use up its budget, and a
        // rotation does not reset it.
        $limit = 'servers:report:'.$server->id;
        if (RateLimiter::tooManyAttempts($limit, 1)) {
            return AgentResponses::error(429, ['Retry-After' => (string) max(1, RateLimiter::availableIn($limit))]);
        }
        RateLimiter::hit($limit, self::SecondsPerReport);

        $validator = Validator::make($request->json()->all(), $this->rules());
        if ($validator->fails()) {
            return AgentResponses::error(422);
        }
        /** @var array{sample_id: string, observed_at: string, agent: array{version: string, os: string, arch: string, distro: string, kernel: string}, host: array{hostname: string, uptime_seconds: int, reboot_required: bool|null, ips: list<string>}, cpu: array{cores: int, used_percent: int|float}, load: array{1: int|float, 5: int|float, 15: int|float}, memory: array{total_bytes: int, available_bytes: int}, filesystems: list<array{mount: string, total_bytes: int, free_bytes: int}>, updates: array{pending: int|null, security: int|null}} $report */
        $report = $validator->validated();
        $now = CarbonImmutable::now();
        $observedAt = CarbonImmutable::parse($report['observed_at']);
        if ($observedAt->lt($now->subMinutes(self::StaleMinutes)) || $observedAt->gt($now->addMinutes(self::FutureMinutes))) {
            return AgentResponses::error(422);
        }
        $interval = ['interval_seconds' => config()->integer('servers.interval_seconds')];

        return match ($record($token, $server->id, $report, $observedAt, $now)) {
            ServerReportOutcome::Stored => response()->json($interval, 202),
            ServerReportOutcome::Duplicate => response()->json($interval, 200),
            ServerReportOutcome::Unauthorized => AgentResponses::error(401),
            ServerReportOutcome::Removed => AgentResponses::error(404),
        };
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        $nonNegativeInteger = ['required', 'integer:strict', 'min:0'];
        $load = ['required', 'numeric:strict', 'min:0'];
        $count = ['present', 'nullable', 'integer:strict', 'min:0'];
        $text = static fn (int $max): array => ['required', 'string', 'max:'.$max, self::Text];

        return [
            'sample_id' => ['required', 'string', 'uuid'],
            // RFC 3339 in UTC, as Go's time.RFC3339(Nano) writes it.
            'observed_at' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?Z$/D', 'date'],
            'agent' => ['required', 'array'],
            'agent.version' => $text(32),
            'agent.os' => $text(32),
            'agent.arch' => $text(32),
            'agent.distro' => $text(64),
            'agent.kernel' => $text(128),
            'host' => ['required', 'array'],
            'host.hostname' => $text(253),
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
            'filesystems.*.mount' => [...$text(256), 'starts_with:/'],
            'filesystems.*.total_bytes' => $nonNegativeInteger,
            'filesystems.*.free_bytes' => $nonNegativeInteger,
            'updates' => ['required', 'array'],
            'updates.pending' => $count,
            'updates.security' => $count,
        ];
    }
}

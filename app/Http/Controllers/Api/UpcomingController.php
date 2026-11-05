<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Contracts\UpcomingPosition;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Client;
use Heptapod\Queries\UpcomingReader;
use Heptapod\Support\Api\CursorCodec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class UpcomingController extends Controller
{
    use ResolvesApiPrincipal;

    private const array DetailSources = ['maintenance' => 'maintenance_name', 'certificate' => 'certificate_issuer', 'domain' => 'domain_registrar', 'task' => 'cron_task_name'];

    public function __invoke(Request $request, UpcomingReader $upcoming, CursorCodec $cursors): JsonResponse
    {
        $user = $this->principal($request);
        $token = $this->token($user);
        /** @var array{days?: int|string, client_id?: int|string, type?: string, limit?: int|string, cursor?: string} $input */
        $input = Validator::make($request->query(), ['days' => ['sometimes', 'integer', 'min:1', 'max:90'], 'client_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', 'in:maintenance,certificate,domain,task'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string']])->validate();
        $days = (int) ($input['days'] ?? 30);
        $limit = (int) ($input['limit'] ?? 50);
        $clientId = isset($input['client_id']) ? (int) $input['client_id'] : null;
        $type = $input['type'] ?? null;
        $binding = $cursors->binding($user, $token, [$days, $clientId, $type, $limit]);
        $state = isset($input['cursor']) ? $cursors->decode($input['cursor'], 'upcoming', $binding) : null;
        if ($state !== null) {
            if (! is_string($state['from'] ?? null) || ! is_string($state['until'] ?? null) || ! is_array($state['after'] ?? null)
                || ! is_string($state['after']['at'] ?? null) || ! is_string($state['after']['key'] ?? null)) {
                throw new \LogicException('Upcoming cursor state is invalid.');
            }
            $from = CarbonImmutable::parse($state['from']);
            $until = CarbonImmutable::parse($state['until']);
            $after = new UpcomingPosition(CarbonImmutable::parse($state['after']['at']), $state['after']['key']);
        } else {
            $from = CarbonImmutable::now('UTC');
            $until = $from->addDays($days);
            $after = null;
        }
        $page = $upcoming->page($user, $from, $until, $clientId, $type, $after, $limit);
        $clientNames = Client::query()->whereKey(array_values(array_unique(array_filter(array_column($page['items'], 'client_id'), static fn (?int $id): bool => $id !== null))))->pluck('name', 'id');
        $last = $page['items'] === [] ? null : array_last($page['items']);
        $next = $page['has_more'] && $last !== null ? $cursors->encode('upcoming', $binding, ['from' => $from->format('Y-m-d\TH:i:s.uP'),
            'until' => $until->format('Y-m-d\TH:i:s.uP'), 'after' => ['at' => $last['at']->format('Y-m-d\TH:i:s.uP'), 'key' => $last['key']]]) : null;

        return response()->json(['data' => array_map(static fn (array $item): array => ['key' => $item['key'], 'type' => $item['type'],
            'at' => $item['at']->toIso8601ZuluString('microsecond'), 'ends_at' => $item['ends_at']?->toIso8601ZuluString('microsecond'), 'check_ref' => $item['check_id'] === null ? null : 'check:'.$item['check_id'],
            'site_ref' => $item['site_id'] === null ? null : 'site:'.$item['site_id'], 'client_ref' => $item['client_id'] === null ? null : 'client:'.$item['client_id'],
            'client_name' => $item['client_id'] === null ? null : $clientNames[$item['client_id']] ?? throw new \LogicException('Upcoming client missing.'),
            'target' => $item['target_type'] === null ? null : ['type' => $item['target_type'], 'id' => $item['target_id']],
            'detail' => $item['detail'], 'detail_source' => self::DetailSources[$item['type']] ?? 'unknown'], $page['items']),
            'range' => ['from' => $from->toIso8601ZuluString('microsecond'), 'until' => $until->toIso8601ZuluString('microsecond')],
            'consistency' => 'live_keyset', 'has_more' => $page['has_more'], 'next_cursor' => $next], 200, ['Cache-Control' => 'private, no-store']);
    }
}

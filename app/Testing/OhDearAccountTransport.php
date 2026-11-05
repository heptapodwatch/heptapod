<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/** SDK transport only. Unexpected endpoints fail instead of reaching Oh Dear.
 * @phpstan-type Definitions array{cron: list<array<string, mixed>>, maintenance: list<array<string, mixed>>, recurring: list<array<string, mixed>>}
 * @phpstan-type Account array{me: array<string, mixed>, monitors: list<array<string, mixed>>, cron: list<array<string, mixed>>, maintenance: list<array<string, mixed>>, recurring: list<array<string, mixed>>, monitor_details?: array<int, Definitions>, responses?: array<string, mixed>}
 */
final class OhDearAccountTransport
{
    /** @param Account $account
     * @param  array<string, int>  $endpointStatuses
     * @param  array<string, \Throwable>  $endpointFailures
     */
    public function fake(array $account, int $status = 200, ?int $failedMonitorPage = null, array $endpointStatuses = [], array $endpointFailures = []): MockClient
    {
        MockClient::destroyGlobal();

        return MockClient::global(['https://ohdear.app/api/*' => self::respond($account, $status, $failedMonitorPage, $endpointStatuses, $endpointFailures)]);
    }

    /** Connector-scoped client answering from a recorded account directory (see tests/Fixtures/ohdear/real/README.md).
     * A failed monitor page serves one monitor per page and answers that page with HTTP 500.
     */
    /** @param array<string, int> $endpointStatuses Fixed HTTP status per API path, e.g. a per-monitor 500. */
    public static function recorded(string $directory, ?int $failedMonitorPage = null, array $endpointStatuses = [], bool $addedMonitor = false): MockClient
    {
        $load = static fn (string $file): array => (array) json_decode((string) file_get_contents("{$directory}/{$file}.json"), true, flags: JSON_THROW_ON_ERROR);
        $monitors = $load('monitors');
        /** @var array<string, mixed> $me */
        $me = $load('me');
        $responses = ['/api/me' => $me, '/api/monitors' => $monitors];
        /** @var list<array{id: int}&array<string, mixed>> $data */
        $data = $monitors['data'];
        foreach (array_column($data, 'id') as $id) {
            foreach (['cron-checks' => 'cron-checks', 'maintenance-periods' => 'maintenance', 'recurring-maintenance-periods' => 'recurring-maintenance'] as $endpoint => $file) {
                $responses["/api/monitors/{$id}/{$endpoint}"] = $load("{$file}-{$id}");
            }
        }

        // A monitor that appears in the account after the plan was made: a copy of the last one with id 506.
        if ($addedMonitor) {
            $last = array_last($data) ?? throw new \LogicException('The recorded account has no monitors.');
            $data[] = [...$last, 'id' => 506, 'url' => 'https://site-6.example', 'sort_url' => 'site-6.example', 'label' => 'site-6.example'];
            foreach (['cron-checks', 'maintenance-periods', 'recurring-maintenance-periods'] as $endpoint) {
                $responses["/api/monitors/506/{$endpoint}"] = $responses["/api/monitors/{$last['id']}/{$endpoint}"];
            }
            /** @var array<string, mixed> $monitors */
            $monitors['data'] = $data;
            $responses['/api/monitors'] = $monitors;
        }
        if ($failedMonitorPage !== null) {
            unset($responses['/api/monitors']);
        }

        return new MockClient(['https://ohdear.app/api/*' => self::respond(['me' => $me, 'monitors' => $data, 'cron' => [], 'maintenance' => [], 'recurring' => [], 'responses' => $responses],
            $failedMonitorPage === null ? 200 : 500, $failedMonitorPage, $endpointStatuses)]);
    }

    /** @param Account $account
     * @param  array<string, int>  $endpointStatuses
     * @param  array<string, \Throwable>  $endpointFailures
     */
    private static function respond(array $account, int $status = 200, ?int $failedMonitorPage = null, array $endpointStatuses = [], array $endpointFailures = []): \Closure
    {
        return static function (PendingRequest $request) use ($account, $status, $failedMonitorPage, $endpointStatuses, $endpointFailures): MockResponse {
            if ($request->getMethod()->value !== 'GET') {
                throw new \LogicException('Account import must be read-only at Oh Dear.');
            }
            $path = parse_url($request->getUrl(), PHP_URL_PATH);
            if (! is_string($path)) {
                throw new \LogicException('Invalid import endpoint URL.');
            }
            /** @var int $page */
            $page = $request->query()->get('page') ?? 1;
            if (isset($endpointFailures[$path])) {
                throw $endpointFailures[$path];
            }
            $responseStatus = $endpointStatuses[$path] ?? ($failedMonitorPage === null || ($path === '/api/monitors' && $page === $failedMonitorPage) ? $status : 200);
            if ($responseStatus === 0) {
                throw new FatalRequestException(new \RuntimeException('fixture-remote-secret'), $request);
            }
            if ($responseStatus !== 200) {
                return MockResponse::make($account['responses'][$path] ?? ['message' => 'fixture-remote-secret'], $responseStatus);
            }
            if (array_key_exists($path, $account['responses'] ?? [])) {
                $body = $account['responses'][$path];
                if ($path === '/api/monitors' && is_array($body)) {
                    $body['data'] = $account['monitors'];
                }

                return MockResponse::make($body);
            }
            if ($path === '/api/me') {
                return MockResponse::make($account['me']);
            }
            if ($path === '/api/monitors') {
                return MockResponse::make(['data' => array_slice($account['monitors'], $page - 1, 1), 'meta' => ['current_page' => $page, 'last_page' => count($account['monitors'])]]);
            }
            if (preg_match('~^/api/monitors/(\d+)/(cron-checks|maintenance-periods|recurring-maintenance-periods)$~D', $path, $matches) === 1) {
                $monitorId = (int) $matches[1];
                $definitions = $account['monitor_details'][$monitorId] ?? ($monitorId === 101 ? $account : throw new \LogicException('Unconfigured import monitor: '.$monitorId));

                return match ($matches[2]) {
                    'cron-checks' => MockResponse::make(['data' => array_slice($definitions['cron'], $page - 1, 1), 'meta' => ['current_page' => $page, 'last_page' => max(1, count($definitions['cron']))]]),
                    'maintenance-periods' => MockResponse::make(['data' => $definitions['maintenance'], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
                    'recurring-maintenance-periods' => MockResponse::make(['data' => $definitions['recurring']]),
                };
            }
            throw new \LogicException('Unexpected import endpoint: '.$path);
        };
    }
}

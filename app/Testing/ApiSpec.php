<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use cebe\openapi\json\JsonPointer;
use cebe\openapi\Reader;
use cebe\openapi\spec\Components;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Response as SpecResponse;
use cebe\openapi\spec\Responses;
use cebe\openapi\spec\Schema;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Spectator\Validation\ResponseValidator;
use Symfony\Component\HttpFoundation\Response;

final class ApiSpec
{
    /** @var array<string, true> Successful, genuinely validated operations only. */
    public array $validated = [];

    private readonly OpenApi $spec;

    /** Central reviewed exceptions. The pinned source is never changed. */
    public const array Exceptions = [
        '/components/schemas/Check/properties/unavailable_reason' => ['reason' => 'Additional nullable translated deployment-capability reason; latest_run_result remains unchanged'],
        // Laravel has no observed bounds on an empty page; SDK accepts null.
        '/components/schemas/PaginatedMonitors/properties/meta/properties/from' => ['reason' => 'Empty paginator bounds are null', 'type' => ['integer', 'null']],
        '/components/schemas/PaginatedMonitors/properties/meta/properties/to' => ['reason' => 'Empty paginator bounds are null', 'type' => ['integer', 'null']],
        // SDK DowntimePeriod explicitly accepts null until target downtime resolves.
        '/components/schemas/DowntimeRecord/properties/ended_at' => ['reason' => 'Open target downtime has no ending', 'type' => ['string', 'null']],
        '/components/responses/NoContent' => ['reason' => 'HTTP 204 has no body per RFC 9110; the spec declares application/json null', 'content' => []],
        '/paths/~1monitors~1{monitorId}~1cron-checks~1sync/post' => ['reason' => 'SDK 4.7 syncCronCheckDefinitions expects {data: [...]}; the pinned spec and schedule-monitor require bare [...]. Heptapod follows the spec without a schema override.'],
        '/paths/~1cron-checks~1{cronCheckId}~1snooze/post/requestBody/content/application~1json/schema/properties/minutes' => ['reason' => 'SDK sends integer, spec says string; positive digits only, max 525600', 'type' => ['integer', 'string']],
        '/paths/~1checks~1{checkId}~1snooze/post/requestBody/content/application~1json/schema/properties/minutes' => ['reason' => 'SDK sends integer, spec says string; positive digits only, max 525600', 'type' => ['integer', 'string']],
        '/paths/~1monitors~1{monitorId}~1http-uptime-metrics/get/parameters' => ['reason' => 'Minute granularity only inside configured raw retention, max 24h; hour/day/week/month windows align outward (floor start, ceil end) to complete buckets with real timestamps'],
        '/paths/~1monitors~1{monitorId}~1uptime/get/parameters' => ['reason' => 'Rollup windows align outward (floor start, ceil end) to complete buckets in the instance timezone; timestamps identify actual buckets'],
        '/components/schemas/UptimePoint/properties/datetime' => ['reason' => 'Buckets for non-whole-hour timezones follow UTC hour boundaries; local day/week/month boundaries are floored to UTC hours without overlap, each UTC hour belongs to exactly one bucket and labels are the floored UTC starts'],
        '/components/schemas/UptimeMetricsHttpPoint/properties/date' => ['reason' => 'For non-whole-hour timezones, bucket boundaries are floored to UTC hours; whole UTC-hour rollups are assigned once to the local calendar bucket containing their last instant'],
        '/components/schemas/Monitor/properties/crawler_headers' => ['reason' => 'Secrets are write-only for every token; nullable crawler headers serialize as null'],
        '/components/schemas/NameValuePair/properties/value' => ['reason' => 'Secret header values serialize as the fixed mask ********, never plaintext; omitted or masked secret writes retain the previous value'],
        '/components/schemas/Monitor/properties/application_health_check_settings/properties/secret' => ['reason' => 'Health secret is write-only and serializes as ********, including uninstalled snapshots; omitted or masked writes retain the previous value'],
        '/components/schemas/UptimeCheckSettings/properties/frequency_unit' => ['reason' => 'The real Oh Dear API returns hour/day (sanitized live data); the pinned spec is incomplete', 'enum' => ['minute', 'hour', 'day', null]],
        '/components/schemas/UptimeCheckSettings/properties/failed_notification_threshold' => ['reason' => 'Uptime notification minutes in 1..1000; null inherits the team default. Incident opening remains unchanged; other checks keep failed-check-count semantics.'],
        '/components/schemas/Monitor/properties/alert_sensitivity' => ['reason' => 'Additional read-only effective sensitivity: preset, alert_after_minutes, recovery_checks, confirms_other_checks, reminders and inherited. Writes use uptime failed_notification_threshold only.'],
        '/paths/~1checks~1{checkId}~1snooze/post' => ['reason' => 'Uninstalled addons reject enable, config, request-run and snooze with 422; disable, unsnooze and monitor delete remain allowed cleanup'],
        '/paths/~1monitors/get/parameters' => ['reason' => 'SDK scalar page/per_page supported alongside bracketed pages; mixed dialects rejected'],
    ];

    /** @var array<string, array{reason: string}> */
    public array $errorExceptions = [];

    public function __construct()
    {
        $this->spec = Reader::readFromYamlFile(base_path('tests/Fixtures/OhDear/ohdear-openapi.yml'));
        foreach (self::Exceptions as $pointer => $exception) {
            if (isset($exception['type'])) {
                $schema = new JsonPointer($pointer)->evaluate($this->spec);
                if (! $schema instanceof Schema) {
                    throw new RuntimeException('Schema exception points to non-schema: '.$pointer);
                }
                $schema->type = $exception['type'];
            } elseif (isset($exception['enum'])) {
                $schema = new JsonPointer($pointer)->evaluate($this->spec);
                if (! $schema instanceof Schema) {
                    throw new RuntimeException('Schema exception points to non-schema: '.$pointer);
                }
                $schema->enum = $exception['enum'];
            } elseif (array_key_exists('content', $exception)) {
                $response = new JsonPointer($pointer)->evaluate($this->spec);
                if (! $response instanceof SpecResponse) {
                    throw new RuntimeException('Response exception points to non-response: '.$pointer);
                }
                $response->content = $exception['content'];
            }
        }
        if (! $this->spec->components instanceof Components) {
            throw new RuntimeException('Spec components missing');
        }
        foreach ($this->operations() as $entry) {
            if (! Route::has('api.'.$entry['operation']->operationId)) {
                continue;
            }
            if (! $entry['operation']->responses instanceof Responses) {
                throw new RuntimeException('Spec responses missing: '.$entry['operation']->operationId);
            }
            foreach ([422, 429] as $status) {
                if (! $entry['operation']->responses->hasResponse((string) $status)) {
                    $pointer = '/paths/'.JsonPointer::encode($entry['path']).'/'.$entry['method'].'/responses/'.$status;
                    $this->errorExceptions[$pointer] = ['reason' => 'Spec omits documented error status'];
                    $entry['operation']->responses[$status] = $status === 422 ? $this->spec->components->responses['Validation'] : new SpecResponse([
                        'description' => 'Rate limited', 'content' => ['application/json' => ['schema' => $this->spec->components->schemas['ErrorMessage']]],
                    ]);
                }
            }
        }
    }

    /** @return list<array{path: string, method: string, operation: Operation}> */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->spec->paths as $path => $item) {
            foreach (['get', 'post', 'put', 'delete', 'patch'] as $method) {
                if ($item->$method instanceof Operation) {
                    if (! is_string($path)) {
                        throw new RuntimeException('Spec path must be a string');
                    }
                    $operations[] = ['path' => $path, 'method' => $method, 'operation' => $item->$method];
                }
            }
        }

        return $operations;
    }

    public function validate(string $method, string $uri, Response $response): void
    {
        $path = substr((string) parse_url($uri, PHP_URL_PATH), strlen('/api'));
        foreach ($this->operations() as $entry) {
            $pattern = preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($entry['path'], '~'));
            if ($entry['operation']->operationId === 'getMonitorByUrl') {
                $pattern = '/monitors/url/.+';
            }
            if (strtolower($method) === $entry['method'] && preg_match('~^'.$pattern.'$~D', $path) === 1) {
                ResponseValidator::validate($response, $entry['operation'], '3.1.2');
                if ($response->isSuccessful()) {
                    $this->validated[$entry['operation']->operationId] = true;
                }

                return;
            }
        }
        throw new RuntimeException('No spec operation for '.$method.' '.$path);
    }
}

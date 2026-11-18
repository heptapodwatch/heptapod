<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\OhDear\OhDearClientFactory;
use Heptapod\Contracts\OhDear\OhDearIdentityConflict;
use Heptapod\Data\Import\ImportPlanData;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Exceptions\CheckConfigurationValidationException;
use Heptapod\Exceptions\InvalidOhDearResponse;
use Heptapod\Exceptions\UndeclaredImportNotice;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\OhDearImportNotices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use OhDear\PhpSdk\Dto\Monitor;
use OhDear\PhpSdk\Exceptions\OhDearException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\PendingRequest;
use Throwable;

/** Plans or imports every monitor of one Oh Dear account. Shared by `heptapod:import-ohdear` and Settings → Import. */
final readonly class ImportOhDearAccount
{
    /**
     * Top-level fields a validation reason may name, with their label. Only for these, and only when the error is on the field
     * itself, the validation message is shown: it names the field, never a source key. Anything nested renders as "<label>: invalid".
     */
    private const array ShownFields = ['url' => 'import.field.url', 'friendly_name' => 'import.field.name', 'group_name' => 'import.field.group',
        'description' => 'import.field.description', 'notes' => 'import.field.notes', 'tags' => 'import.field.tags', 'client_id' => 'import.field.client',
        'checks' => 'import.field.checks', 'send_report_to_emails' => 'import.field.report', 'include_check_types_in_report' => 'import.field.report',
        'name' => 'import.field.maintenance', 'starts_at' => 'import.field.maintenance', 'ends_at' => 'import.field.maintenance', 'recurrence_type' => 'import.field.maintenance',
        'days_of_week' => 'import.field.maintenance', 'day_of_week' => 'import.field.maintenance', 'day_of_month' => 'import.field.maintenance', 'nth_weekday' => 'import.field.maintenance',
        'start_time' => 'import.field.maintenance', 'end_time' => 'import.field.maintenance', 'timezone' => 'import.field.maintenance', 'interval' => 'import.field.maintenance',
        'anchor_date' => 'import.field.maintenance'];

    /** Secret-bearing top-level fields: never a message, key or index. */
    private const array SecretFields = ['crawler_headers' => 'import.field.crawler_headers', 'crawler_settings' => 'import.field.crawler_headers'];

    public function __construct(private ImportOhDearMonitor $import, private InstanceSettings $instance, private OhDearClientFactory $clients, private CheckRegistry $registry) {}

    /**
     * @param  array<int, int|null>|null  $approved  UI imports: the only source monitors to import, each with its approved client for a
     *                                               new site (null for a site that existed at plan time). Null (CLI): every monitor, new sites in Internal.
     * @param  (\Closure(ImportPlanRowData): void)|null  $progress  Called after every monitor.
     */
    public function __invoke(#[\SensitiveParameter] string $token, bool $dryRun, ?array $approved = null, ?\Closure $progress = null): ImportPlanData
    {
        $sdk = $this->clients->make($token, $dryRun);
        $teams = [];
        $rows = [];
        $remoteError = null;
        try {
            /** @var list<array{id: int, name: string, timezone?: string}> $sourceTeams */
            $sourceTeams = $sdk->me()->teams;
            foreach ($sourceTeams as $team) {
                $teams[] = ['id' => $team['id'], 'name' => $team['name']];
            }
            foreach ($sdk->monitors() as $monitor) {
                $timezone = $this->instance->timezone;
                foreach ($sourceTeams as $team) {
                    if ($team['id'] === $monitor->teamId && isset($team['timezone'])) {
                        $timezone = $team['timezone'];
                        break;
                    }
                }
                try {
                    if ($approved !== null && ! array_key_exists($monitor->id, $approved)) {
                        $rows[] = $this->notImported($monitor, 'plan:missing', 'Not in the plan; show the plan again to import it.');
                    } else {
                        $rows[] = ($this->import)($sdk, $monitor, $timezone, $dryRun, $approved[$monitor->id] ?? null, $approved !== null);
                    }
                } catch (ValidationException $exception) {
                    $rows[] = $this->failed($monitor, $this->validationDetails($exception), $exception);
                } catch (OhDearException|RequestException|FatalRequestException|InvalidOhDearResponse|OhDearIdentityConflict $exception) {
                    // Only source failures are recovered per site; any other fault is local and stops the import (see stopOnLocalFault).
                    $rows[] = $this->failed($monitor, $this->failureDetails($exception), $exception);
                }
                if ($progress instanceof \Closure) {
                    $progress(array_last($rows));
                }
            }
        } catch (FatalRequestException $exception) {
            $remoteError = __('import.connection_failed').'; '.$this->failureDetails($exception);
        } catch (OhDearException $exception) {
            // The SDK exception contains the upstream response; never print it or the token.
            $remoteError = __('import.remote_failed', ['status' => $exception->response->status()]).'; '.$this->failureDetails($exception);
        }
        if (! $dryRun) {
            DB::statement('ANALYZE sites, taggables, tags, client_user');
        }

        return new ImportPlanData($dryRun, $teams, $rows, $remoteError);
    }

    private function notImported(Monitor $monitor, string $code, string $reason): ImportPlanRowData
    {
        return new ImportPlanRowData((string) $monitor->id, $monitor->url, $monitor->label, $monitor->teamId, array_values(array_filter($monitor->tags, is_string(...))),
            ImportOperation::Skipped, [], [], [OhDearImportNotices::key($code)], $reason);
    }

    private function failed(Monitor $monitor, string $reason, Throwable $exception): ImportPlanRowData
    {
        $this->logFailure($monitor, $exception, $reason);
        $existing = Site::query()->where('oh_dear_id', $monitor->id)->first();
        $siteId = $existing?->id;

        return new ImportPlanRowData((string) $monitor->id, $monitor->url, $monitor->label, $monitor->teamId, array_values(array_filter($monitor->tags, is_string(...))), ImportOperation::Failed, [], [], [$this->failedNotice($exception)], $reason, $siteId);
    }

    /** Oh Dear unreachable or answering badly is a different cause than a site Heptapod could not import. */
    private function failedNotice(Throwable $exception): string
    {
        $remote = $exception instanceof OhDearException || $exception instanceof RequestException || $exception instanceof FatalRequestException || $exception instanceof InvalidOhDearResponse;

        return $remote ? 'import.notice.failed_remote' : 'import.notice.failed';
    }

    /**
     * The one boundary of every import entry point (CLI, plan request, queued run) for a fault that is not a source or
     * validation failure (database, programming error): the import stops and the log names only the class and place.
     * The message can carry source values, so it is never logged or shown. An undeclared notice stays loud.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @param  \Closure(): T  $onLocalFault
     * @return T
     */
    public static function stopOnLocalFault(\Closure $work, \Closure $onLocalFault): mixed
    {
        try {
            return $work();
        } catch (UndeclaredImportNotice $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Oh Dear import stopped by a local error', ['exception' => $exception::class, 'file' => $exception->getFile(), 'line' => $exception->getLine()]);

            return $onLocalFault();
        }
    }

    /**
     * Validation paths and messages can carry source keys (e.g. a crawler header map keyed by a credential), so a reason names
     * only a known field label; the validation message is added only for a top-level field from ShownFields.
     */
    private function validationDetails(ValidationException $exception): string
    {
        $fields = [];
        /** @var array<string, list<string>> $errors */
        $errors = $exception->errors();
        foreach ($errors as $path => $messages) {
            $top = explode('.', $path)[0];
            $settings = $exception instanceof CheckConfigurationValidationException ? $exception->check : (str_ends_with($top, '_check_settings') ? CheckKey::tryFrom(substr($top, 0, -strlen('_check_settings'))) : null);
            $fields[] = match (true) {
                $settings instanceof CheckKey => __('import.field_invalid', ['field' => __('import.field.check_settings', ['check' => in_array($settings, $this->registry->keys(), true) ? __($this->registry->definition($settings)->labelKey) : $settings->value])]),
                isset(self::ShownFields[$top]) && $path === $top => __(self::ShownFields[$top]).': '.implode(' ', $messages),
                isset(self::ShownFields[$top]) => __('import.field_invalid', ['field' => __(self::ShownFields[$top])]),
                isset(self::SecretFields[$top]) => __('import.field_invalid', ['field' => __(self::SecretFields[$top])]),
                default => __('import.invalid_setting'),
            };
        }

        return __('import.invalid', ['fields' => implode('; ', array_unique($fields))]);
    }

    /** The operator's record of a failure. It logs the same vetted reason the user sees, never the exception message, which can echo source secrets. */
    private function logFailure(?Monitor $monitor, Throwable $exception, string $reason): void
    {
        Log::warning('Oh Dear import failed', ['ohdear_monitor_id' => $monitor?->id, 'exception' => $exception::class, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'reason' => $reason]);
    }

    private function failureDetails(#[\SensitiveParameter] Throwable $exception): string
    {
        // An InvalidOhDearResponse message is a lang key naming the endpoint (see the class).
        $message = $exception instanceof InvalidOhDearResponse ? __($exception->getMessage()) : __('import.unexpected_failed');
        $response = match (true) {
            $exception instanceof OhDearException => $exception->response,
            $exception instanceof RequestException => $exception->getResponse(),
            default => null,
        };
        $pending = $response?->getPendingRequest() ?? ($exception instanceof FatalRequestException ? $exception->getPendingRequest() : null);
        if ($pending instanceof PendingRequest) {
            $path = $pending->getRequest()->resolveEndpoint();
            /** @var string $endpoint */
            $endpoint = match ($path) {
                '/me' => 'me',
                '/monitors' => 'monitors',
                default => preg_match('~^/monitors/[0-9]+/(cron-checks|maintenance-periods|recurring-maintenance-periods)$~D', $path, $matches) === 1 ? 'monitors/{id}/'.$matches[1] : __('import.unknown_endpoint'),
            };
            /** @var string $noResponse */
            $noResponse = __('import.no_response');
            $message .= ' '.__('import.request_failed', ['method' => $pending->getMethod()->value, 'endpoint' => $endpoint, 'status' => $response?->status() ?? $noResponse]);
        }

        return explode('@', class_basename($exception))[0].': '.$message;
    }
}

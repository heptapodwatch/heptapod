<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\OhDear\OhDearClientFactory;
use Heptapod\Contracts\OhDear\OhDearIdentityConflict;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Data\Import\ImportPlanData;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Exceptions\InvalidOhDearRecurringResponse;
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
    /** Site, maintenance and recurring attribute names a validation path may name besides check settings fields. */
    private const array FieldNames = ['url', 'friendly_name', 'group_name', 'description', 'notes', 'tags', 'checks', 'crawler_headers', 'crawler_settings', 'headers', 'name', 'value',
        'send_report_to_emails', 'include_check_types_in_report', 'repositories', 'label', 'team_id', 'type', 'values', 'frequency_value', 'frequency_unit', 'failed_notification_threshold',
        'starts_at', 'ends_at', 'recurrence_type', 'days_of_week', 'day_of_week', 'day_of_month', 'nth_weekday', 'start_time', 'end_time', 'timezone', 'interval', 'anchor_date'];

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
                } catch (OhDearException|RequestException|FatalRequestException|InvalidOhDearRecurringResponse|OhDearIdentityConflict $exception) {
                    // Only source failures are recovered per site; any other fault is local and stops the import (see reportLocalFault).
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
        $remote = $exception instanceof OhDearException || $exception instanceof RequestException || $exception instanceof FatalRequestException || $exception instanceof InvalidOhDearRecurringResponse;

        return $remote ? 'import.notice.failed_remote' : 'import.notice.failed';
    }

    /**
     * The boundary for a fault that is not a source or validation failure (database, programming error): the import stops,
     * and the log names only the class and place. The message can carry source values, so it is never logged or shown.
     */
    public static function reportLocalFault(Throwable $exception): void
    {
        Log::error('Oh Dear import stopped by a local error', ['exception' => $exception::class, 'file' => $exception->getFile(), 'line' => $exception->getLine()]);
    }

    /**
     * Validation paths can contain source-supplied keys (e.g. a crawler header map), and their messages repeat the path.
     * Only a path made of known field names and list indexes is shown with its message; anything else is a generic label.
     */
    private function validationDetails(ValidationException $exception): string
    {
        $known = self::FieldNames;
        $collect = static function (Field $field) use (&$collect, &$known): void {
            foreach ($field->properties as $name => $child) {
                $known[] = $name;
                $collect($child);
            }
            if ($field->items instanceof Field) {
                $collect($field->items);
            }
        };
        foreach ($this->registry->keys() as $key) {
            $known[] = $key->value.'_check_settings';
            foreach ($this->registry->definition($key)->configSchema->fields as $name => $field) {
                $known[] = $name;
                $collect($field);
            }
        }
        $fields = [];
        /** @var array<string, list<string>> $errors */
        $errors = $exception->errors();
        foreach ($errors as $path => $messages) {
            $safe = array_all(explode('.', $path), static fn (string $segment): bool => ctype_digit($segment) || in_array($segment, $known, true));
            $fields[] = $safe ? $path.': '.implode(' ', $messages) : __('import.invalid_setting');
        }

        return __('import.invalid', ['fields' => implode('; ', array_unique($fields))]);
    }

    /** The operator's record of a failure. It logs the same vetted reason the user sees, never the exception message, which can echo source secrets. */
    private function logFailure(?Monitor $monitor, Throwable $exception, string $reason): void
    {
        Log::warning('Oh Dear import failed', ['ohdear_monitor_id' => $monitor?->id, 'exception' => $exception::class, 'reason' => $reason]);
    }

    private function failureDetails(#[\SensitiveParameter] Throwable $exception): string
    {
        $message = $exception instanceof InvalidOhDearRecurringResponse ? __('import.invalid_recurring_response') : __('import.unexpected_failed');
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

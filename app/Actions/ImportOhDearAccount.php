<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\OhDear\OhDearClientFactory;
use Heptapod\Data\Import\ImportPlanData;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Exceptions\InvalidOhDearRecurringResponse;
use Heptapod\Exceptions\UndeclaredImportNotice;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\OhDearImportNotices;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private ImportOhDearMonitor $import, private InstanceSettings $instance, private OhDearClientFactory $clients) {}

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
                } catch (UndeclaredImportNotice $exception) {
                    throw $exception;
                } catch (ValidationException $exception) {
                    // Validation messages may include source secrets; report field paths only.
                    $rows[] = $this->failed($monitor, __('import.invalid', ['fields' => implode(', ', array_keys($exception->errors()))]));
                } catch (Throwable $exception) {
                    $rows[] = $this->failed($monitor, $this->failureDetails($exception));
                }
                if ($progress instanceof \Closure) {
                    $progress(array_last($rows));
                }
            }
        } catch (UndeclaredImportNotice $exception) {
            throw $exception;
        } catch (FatalRequestException $exception) {
            $remoteError = __('import.connection_failed').'; '.$this->failureDetails($exception);
        } catch (OhDearException $exception) {
            // The SDK exception contains the upstream response; never print it or the token.
            $remoteError = __('import.remote_failed', ['status' => $exception->response->status()]).'; '.$this->failureDetails($exception);
        } catch (Throwable $exception) {
            $remoteError = $this->failureDetails($exception);
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

    private function failed(Monitor $monitor, string $reason): ImportPlanRowData
    {
        $existing = Site::query()->where('oh_dear_id', $monitor->id)->first();
        $siteId = $existing?->id;

        return new ImportPlanRowData((string) $monitor->id, $monitor->url, $monitor->label, $monitor->teamId, array_values(array_filter($monitor->tags, is_string(...))), ImportOperation::Failed, [], [], ['import.notice.failed'], $reason, $siteId);
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

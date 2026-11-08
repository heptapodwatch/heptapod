<?php

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Enums\IncidentCause;
use Heptapod\Events\EventFlapGroups;
use Heptapod\Support\RedactionContext;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * @property int|null $flap_group_id
 * @property Site|null $site
 * @property Check|null $check
 * @property string|null $site_name
 * @property string|null $site_url
 * @property string|null $client_name
 * @property int|null $client_id
 * @property CheckKey|null $check_type
 * @property CheckResult|null $from_result
 * @property CheckResult|null $to_result
 * @property IncidentCause|null $cause
 * @property SuppressionComponent|null $component_type
 * @property int|null $component_id
 * @property array<string, mixed> $payload
 * @property int $payload_version
 * @property bool $suppressed
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $suppression_evaluated_at
 * @property CarbonImmutable|null $notification_routed_at
 * @property int|null $system_issue_id set only on system.issue_opened and system.issue_resolved, which have no site
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
#[WithoutTimestamps]
final class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    // Protocol facts, not user data: masking these can invalidate routing, deduplication or timestamp parsing.
    private const array PayloadProtocolFacts = ['result', 'from_result', 'from_cause', 'to_cause', 'from_reason_key', 'to_reason_key', 'run_id', 'run_ended_at', 'summary',
        'failure_started_at', 'recovered_at', 'since', 'summary_params.presentation', 'summary_params.resolution_key'];

    public function redactPayload(Site $site): void
    {
        $context = RedactionContext::forSite($site, $this->check);
        $display = Arr::except($this->payload, self::PayloadProtocolFacts);
        $display = SecretRedactor::redactStringValues($display, $context);
        /** @var array<string, mixed> $payload String-value redaction preserves payload keys. */
        $payload = array_replace_recursive($this->payload, $display);
        $this->payload = $payload;
    }

    protected static function booted(): void
    {
        self::created(static function (self $event): void {
            app(EventFlapGroups::class)->project($event);
        });
        self::creating(function (self $event): void {
            if ($event->site_id === null) {
                if ($event->type !== 'notification.digest' && $event->system_issue_id === null) {
                    throw new \LogicException('Only digest and system issue events can be created without a site.');
                }

                return;
            }
            $site = $event->site ?? throw new \LogicException('An event must snapshot an existing site.');
            $client = $site->client ?? throw new \LogicException('An event site must have a client.');
            $event->site_name = $site->displayName();
            $event->site_url = SecretRedactor::redact($site->url, [], [$site->url]);
            $event->client_name = $client->name;
            $event->client_id = $site->client_id;
            $event->check_type = $event->check?->type;
        });
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return ['flap_group_id' => 'integer', 'from_result' => CheckResult::class, 'to_result' => CheckResult::class, 'cause' => IncidentCause::class,
            'payload' => 'array', 'payload_version' => 'integer', 'suppressed' => 'boolean', 'check_type' => CheckKey::class,
            'component_type' => SuppressionComponent::class, 'component_id' => 'integer',
            'occurred_at' => 'immutable_datetime', 'suppression_evaluated_at' => 'immutable_datetime', 'notification_routed_at' => 'immutable_datetime'];
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    #[Scope]
    protected function feedVisible(Builder $query): Builder
    {
        // Notification reminders do not open another timeline failure.
        $query->getQuery()->where('type', '<>', 'incident.still_down');
        // Messages sent to a client are listed on the client page, not in Activity or the site and check timelines.
        $query->getQuery()->whereNotIn('type', ['client_message.findings', 'client_message.incident']);
        /** A task's first successful observation is setup, not a state change. */
        $query->getQuery()->whereRaw("NOT ((type = 'cron.task_result_changed' AND (payload->>'from_result' IS NULL OR payload->>'from_result' = 'pending') AND payload->>'result' = 'succeeded') IS TRUE)");
        // Initial observations are not changes. Incident transitions own their run's timeline line.
        $query->getQuery()->whereRaw("(type <> 'check.result_changed' OR (from_result IS NOT NULL AND from_result <> 'pending' AND from_result <> to_result AND NOT EXISTS (SELECT 1 FROM events transition WHERE transition.check_id IS NOT DISTINCT FROM events.check_id AND transition.site_id IS NOT DISTINCT FROM events.site_id AND transition.occurred_at = events.occurred_at AND transition.type IN ('incident.opened', 'incident.resolved', 'incident.cause_changed') AND transition.payload->>'run_id' = events.payload->>'run_id' AND transition.payload->>'run_ended_at' = events.payload->>'run_ended_at'))) ");
        // Sitemap diffs retain the baseline run in their dedupe key, not the current run in their payload.
        // Their exact observation time and transition identify the incident line that already describes this run.
        $query->getQuery()->whereRaw("NOT (type = 'sitemap.issues_changed' AND EXISTS (SELECT 1 FROM events transition WHERE transition.check_id = events.check_id AND transition.site_id = events.site_id AND transition.occurred_at = events.occurred_at AND transition.from_result IS NOT DISTINCT FROM events.from_result AND transition.to_result IS NOT DISTINCT FROM events.to_result AND transition.type IN ('incident.opened', 'incident.resolved', 'incident.cause_changed')))");
        $dnsRunId = $this->dnsRunIdSql();
        $query->getQuery()->whereRaw("NOT (type = 'check.result_changed' AND events.to_result IN ('warning', 'failed', 'errored-or-timed-out') AND EXISTS (SELECT 1 FROM events dns_change WHERE dns_change.type = 'dns.records_changed' AND dns_change.check_id IS NOT DISTINCT FROM events.check_id AND dns_change.site_id IS NOT DISTINCT FROM events.site_id AND dns_change.occurred_at = events.occurred_at AND dns_change.from_result IS NOT DISTINCT FROM events.from_result AND dns_change.to_result IS NOT DISTINCT FROM events.to_result AND jsonb_typeof(events.payload->'run_id') = 'number' AND {$dnsRunId} = events.payload->>'run_id'))");
        // A threshold-pending failure belongs to the incident that subsequently opens.
        $query->getQuery()->whereRaw("NOT (type = 'check.result_changed' AND to_result IN ('failed', 'errored-or-timed-out') AND EXISTS (SELECT 1 FROM incidents failure_window WHERE failure_window.check_id = events.check_id AND failure_window.first_failure_at <= events.occurred_at AND (failure_window.resolved_at IS NULL OR events.occurred_at <= failure_window.resolved_at)))");

        return $query;
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    #[Scope]
    protected function incidentAlertPreviewTypes(Builder $query): Builder
    {
        $query->getQuery()->whereIn('type', ['incident.opened', 'incident.still_down', 'incident.resolved']);

        return $query;
    }

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return literal-string */
    private function dnsRunIdSql(): string
    {
        // Invalid protocol keys deliberately fail the query instead of creating duplicate timeline rows.
        return "CASE WHEN dns_change.type <> 'dns.records_changed' THEN NULL
            WHEN dns_change.dedupe_key ~ '^dns:[1-9][0-9]*:[1-9][0-9]*$'
                AND (dns_change.check_id IS NULL OR split_part(dns_change.dedupe_key, ':', 2) = dns_change.check_id::text) THEN split_part(dns_change.dedupe_key, ':', 3)
            ELSE ('Invalid DNS event dedupe key: ' || coalesce(dns_change.dedupe_key, 'NULL'))::bigint::text END";
    }
}

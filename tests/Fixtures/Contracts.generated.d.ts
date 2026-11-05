declare namespace Illuminate {
    export type CursorPaginator<TKey, TValue> = {
        data: TKey extends string ? Record<TKey, TValue> : TValue[];
        links: {
            url: string | null;
            label: string;
            active: boolean;
        }[];
        meta: {
            path: string;
            per_page: number;
            next_cursor: string | null;
            next_page_url: string | null;
            prev_cursor: string | null;
            prev_page_url: string | null;
        };
    };
    export type CursorPaginatorInterface<TKey, TValue> =
        Illuminate.CursorPaginator<TKey, TValue>;
    export type LengthAwarePaginator<TKey, TValue> = {
        data: TKey extends string ? Record<TKey, TValue> : TValue[];
        links: {
            url: string | null;
            label: string;
            active: boolean;
        }[];
        meta: {
            total: number;
            current_page: number;
            first_page_url: string;
            from: number | null;
            last_page: number;
            last_page_url: string;
            next_page_url: string | null;
            path: string;
            per_page: number;
            prev_page_url: string | null;
            to: number | null;
        };
    };
    export type LengthAwarePaginatorInterface<TKey, TValue> =
        Illuminate.LengthAwarePaginator<TKey, TValue>;
}
declare namespace Heptapod {
    namespace Contracts {
        export type AddonEvent = {
            readonly presentationParams: Record<string | number, any> | null;
            readonly type: string;
            readonly dedupeKey: string;
            readonly version: number;
            readonly payload: Record<string, any>;
            readonly subject: Heptapod.Contracts.SuppressionSubject | null;
        };
        export type AvailabilityFailure = 'tls' | 'http_5xx';
        export type CheckDefinition = {
            readonly key: Heptapod.Contracts.CheckKey;
            readonly labelKey: string;
            readonly icon: string;
            readonly queue: string;
            readonly timeout: number;
            readonly defaultInterval: number;
            readonly defaultFailureThreshold: number;
            readonly executionMode: Heptapod.Contracts.ExecutionMode;
            readonly configSchema: Heptapod.Contracts.Schema.Schema;
            readonly metricSchema: Heptapod.Contracts.Schema.Schema;
            readonly emittedEvents: string[];
            readonly requiresHttps: boolean;
            readonly recommended: boolean;
            readonly detailDataClass: string | null;
            readonly minimumIncreaseInMs: number | null;
        };
        export type CheckDetailData = {
            readonly check_type: Heptapod.Contracts.CheckKey;
        };
        export type CheckError = {
            readonly class: string;
            readonly message: string;
        };
        export type CheckHistoryData = {
            readonly check_type: Heptapod.Contracts.CheckKey;
        };
        export type CheckKey =
            | 'uptime'
            | 'performance'
            | 'certificate_health'
            | 'broken_links'
            | 'mixed_content'
            | 'dns'
            | 'domain'
            | 'lighthouse'
            | 'cron'
            | 'application_health'
            | 'sitemap';
        export type CheckOutcome = {
            readonly result: Heptapod.Contracts.RunResult;
            readonly summary: string;
            readonly summaryParams: Record<
                string,
                number | string | boolean | null
            >;
            readonly metrics: Record<string, unknown> | null;
            readonly evidence: Record<string, unknown> | null;
            readonly events: Heptapod.Contracts.AddonEvent[];
            readonly error: Heptapod.Contracts.CheckError | null;
            readonly incomplete: boolean;
            readonly confirmedIncompleteFailure: boolean;
            readonly availabilityFailure: Heptapod.Contracts.AvailabilityFailure | null;
            readonly verifiedNothing: boolean;
        };
        export type CheckResult =
            | 'pending'
            | 'succeeded'
            | 'warning'
            | 'failed'
            | 'errored-or-timed-out';
        export type CheckRunPresentationData = {
            readonly more_reasons: number;
            readonly headline_count: number;
            readonly parts: Heptapod.Contracts.CheckRunPresentationPartData[];
            readonly explanation: Heptapod.Contracts.CheckRunPresentationPartData | null;
            readonly history_label: Heptapod.Contracts.CheckRunPresentationPartData | null;
            readonly names_check: boolean;
            readonly headline_limit: number;
            readonly changes: Heptapod.Contracts.CheckRunPresentationPartData[];
        };
        export type CheckRunPresentationPartData = {
            readonly key: string;
            readonly params: Record<string, string | number | boolean | null>;
            readonly labels: Record<string, string>;
            readonly shortKey: string | null;
        };
        export type DomainRegistrationSource = 'rdap' | 'whois';
        export type ExecutionMode = 'poll' | 'push' | 'derived';
        export type PreviousResult = {
            readonly run: Heptapod.Contracts.RunReference;
            readonly result: Heptapod.Contracts.RunResult;
            readonly startedAt: string | null;
            readonly summary: string;
            readonly summaryParams: Record<
                string,
                number | string | boolean | null
            >;
            readonly metrics: Record<string, any> | null;
        };
        export type RunPhase =
            | 'scheduled'
            | 'queued'
            | 'running'
            | 'done'
            | 'failed'
            | 'late'
            | 'skipped'
            | 'cancelled';
        export type RunReference = {
            readonly id: number;
            readonly endedAt: string;
        };
        export type RunResult =
            | 'succeeded'
            | 'warning'
            | 'failed'
            | 'errored-or-timed-out';
        export type SuppressionComponent = 'cron' | 'application_health';
        export type SuppressionDecision = {
            readonly suppressed: boolean;
            readonly reason: string | null;
            readonly evaluatedAt: string;
        };
        export type SuppressionSubject = {
            readonly checkId: number;
            readonly componentType: Heptapod.Contracts.SuppressionComponent | null;
            readonly componentId: number | null;
        };
        export type TlsFailureReason =
            | 'name_mismatch'
            | 'expired'
            | 'self_signed'
            | 'incomplete_chain'
            | 'not_yet_valid'
            | 'date_invalid'
            | 'handshake';
        namespace Findings {
            export type AvailableFindingsSnapshot = {
                readonly check_id: number;
                readonly type: Heptapod.Contracts.CheckKey;
                readonly snapshot_ref: string;
                readonly run: Heptapod.Contracts.RunReference;
                readonly observed_at: string;
                readonly coverage: Heptapod.Contracts.Findings.FindingsCoverage;
                readonly supported_filters: Heptapod.Contracts.Findings.FindingStatus[];
                readonly supported_baseline_comparison: boolean;
                readonly dataset_version: number;
            };
            export type FindingStatus =
                | 'broken'
                | 'not_verifiable'
                | 'unchecked'
                | 'coverage_limit';
            export type FindingsCoverage = {
                readonly discovery_complete: boolean | null;
                readonly crawl_truncated: boolean | null;
                readonly page_cap_reached: boolean | null;
                readonly resource_cap_reached: boolean | null;
                readonly time_limit_reached: boolean | null;
                readonly comparison_limit_reached: boolean | null;
                readonly stored_rows_complete: boolean | null;
                readonly stored_not_verifiable_complete: boolean | null;
                readonly unchecked_rows_available: boolean;
                readonly legacy: boolean;
                readonly counters: Record<string, number | null> | null;
                readonly limits: Record<string, number | null> | null;
            };
            export type FindingsFilter = {
                readonly status: Heptapod.Contracts.Findings.FindingStatus | null;
                readonly only_new_since: boolean;
            };
            export type FindingsPage = {
                readonly snapshot: Heptapod.Contracts.Findings.AvailableFindingsSnapshot;
                readonly baseline: Heptapod.Contracts.Findings.AvailableFindingsSnapshot | null;
                readonly baseline_status: string;
                readonly rows: Heptapod.Contracts.Findings.FindingsRow[];
                readonly next_position: Heptapod.Contracts.Findings.FindingsPosition | null;
                readonly has_more: boolean;
            };
            export type FindingsPosition = {
                readonly group: Heptapod.Contracts.Findings.FindingsSourceGroup;
                readonly position: number;
            };
            export type FindingsRow = {
                readonly row_ref: string;
                readonly finding_identity: string;
                readonly status: Heptapod.Contracts.Findings.FindingStatus;
                readonly issue_code: string | null;
                readonly http_status: number | null;
                readonly kind: string | null;
                readonly internal: boolean | null;
                readonly url: string;
                readonly found_on: string | null;
                readonly link_text: string | null;
                readonly error_message: string | null;
                readonly first_seen_at: string | null;
                readonly new_since_opening: boolean | null;
                readonly new_since_baseline: boolean | null;
                readonly carried_forward: boolean | null;
                readonly source_observed_at: string | null;
                readonly field_truncation: string[];
            };
            export type FindingsSourceGroup = 'rows' | 'mixed' | 'verification';
            export type FindingsUnavailableReason =
                | 'not_retained'
                | 'no_observation'
                | 'comparison_limit'
                | 'legacy_metadata_missing'
                | 'incomparable';
            export type FindingsUnavailableStatus =
                | 'evidence_expired'
                | 'unsupported'
                | 'no_dataset';
            export type UnavailableFindingsSnapshot = {
                readonly status: Heptapod.Contracts.Findings.FindingsUnavailableStatus;
                readonly check_id: number;
                readonly type: Heptapod.Contracts.CheckKey;
                readonly requested_run: Heptapod.Contracts.RunReference;
                readonly reason: Heptapod.Contracts.Findings.FindingsUnavailableReason;
                readonly coverage: Heptapod.Contracts.Findings.FindingsCoverage | null;
            };
        }
        namespace Http {
            export type AutomatedCheckBlockReason =
                | 'bot_protection'
                | 'rate_limited';
            export type FetchFailureKind =
                | 'timeout'
                | 'too_large'
                | 'tls'
                | 'dns'
                | 'connect'
                | 'redirect_blocked'
                | 'too_many_redirects'
                | 'private_target'
                | 'http_status'
                | 'invalid_request';
            export type RedirectPolicy = 'off' | 'operator' | 'untrusted';
        }
        namespace Mail {
            export type AlertMailEvent =
                | 'incident.opened'
                | 'incident.still_down'
                | 'incident.resolved'
                | 'warning'
                | 'changed'
                | 'check-error'
                | 'incident.cause_changed';
            export type MailTone =
                | 'down'
                | 'failed'
                | 'warn'
                | 'ok'
                | 'error'
                | 'info'
                | 'neutral';
        }
        namespace Notifications {
            export type AlertMessage = {
                readonly eventId: number;
                readonly severity: Heptapod.Contracts.Notifications.AlertSeverity;
                readonly titleKey: string;
                readonly titleParams: Record<string, string | number>;
                readonly summaryKey: string;
                readonly summaryParams: Record<string, string | number>;
                readonly siteId: number | null;
                readonly siteLabel: string;
                readonly siteUrl: string;
                readonly checkType: string | null;
                readonly since: string;
                readonly downtimeSeconds: number | null;
                readonly cause: string | null;
                readonly link: string | null;
                readonly isTest: boolean;
                readonly clientName: string | null;
                readonly checkTypes: string[] | null;
                readonly affectedChecks: string[];
                readonly recheckingChecks: string[];
            };
            export type AlertSeverity =
                | 'down'
                | 'recovered'
                | 'warning'
                | 'error'
                | 'info';
            export type DeliveryResult = {
                readonly status: string;
                readonly reason: string | null;
                readonly providerMessageId: string | null;
                readonly diagnosis: string | null;
            };
        }
        namespace OhDear {
            export type CheckData = {
                readonly id: number;
                readonly type: Heptapod.Contracts.CheckKey;
                readonly label: string;
                readonly enabled: boolean;
                readonly latest_run_ended_at: string | null;
                readonly latest_run_result: Heptapod.Contracts.CheckResult;
                readonly summary: string | null;
                readonly settings: object;
                readonly active_snooze: Heptapod.Contracts.OhDear.SnoozeData | null;
                readonly average_response_time_in_ms?: number | null;
                readonly unavailable_reason: string | null;
            };
            export type CheckRunData = {
                readonly result: string;
                readonly id: number;
                readonly check_id: number;
                readonly check_type: Heptapod.Contracts.CheckKey;
                readonly started_at: string | null;
                readonly ended_at: string;
                readonly ohdear_url: string;
            };
            export type CheckSummaryData = {
                readonly result: string | null;
                readonly summary: string;
            };
            export type MonitorData = {
                readonly type: string;
                readonly id: number;
                readonly team_id: number;
                readonly url: string;
                readonly uses_https: boolean;
                readonly sort_url: string;
                readonly label: string;
                readonly group_name: string;
                readonly tags: string[];
                readonly description: string | null;
                readonly notes: string | null;
                readonly latest_run_date: string | null;
                readonly summarized_check_result: Heptapod.Contracts.CheckResult;
                readonly checks: Heptapod.Contracts.OhDear.CheckData[];
                readonly uptime_check_settings: Record<string, any>;
                readonly badge_id: string;
                readonly marked_for_deletion_at: string | null;
                readonly created_at: string;
                readonly updated_at: string;
                readonly certificate_health_check_settings?: Record<
                    string,
                    any
                > | null;
                readonly broken_links_check_settings?: Record<
                    string,
                    any
                > | null;
                readonly dns_check_settings?: Record<string, any> | null;
                readonly lighthouse_check_settings?: Record<string, any> | null;
                readonly application_health_check_settings?: Record<
                    string,
                    any
                > | null;
                readonly domain_check_settings?: Record<string, any> | null;
                readonly performance_check_settings?: Record<
                    string,
                    any
                > | null;
                readonly sitemap_check_settings?: Record<string, any> | null;
                readonly crawler_headers?:
                    | {
                          name: string;
                          value: string;
                      }[]
                    | null;
                readonly send_report_to_emails?: string[] | null;
                readonly include_check_types_in_report?:
                    | Heptapod.Contracts.CheckKey[]
                    | null;
            };
            export type SnoozeData = {
                readonly id: number;
                readonly starts_at: string;
                readonly ends_at: string;
                readonly snoozed_by: string;
                readonly unsnoozed_by: string | null;
                readonly source: string;
                readonly created_at: string;
            };
        }
        namespace Schema {
            export type Field = {
                readonly type: Heptapod.Contracts.Schema.FieldType;
                readonly rules: string[];
                readonly properties: Record<
                    string,
                    Heptapod.Contracts.Schema.Field
                >;
                readonly items: Heptapod.Contracts.Schema.Field | null;
                readonly presentation: Heptapod.Contracts.Schema.FieldPresentation | null;
                readonly additionalProperties: boolean;
                readonly targetUrl: boolean;
                readonly default?:
                    | Record<string | number, any>
                    | string
                    | number
                    | boolean
                    | null;
                readonly uppercase: boolean;
                readonly trimmedStrings: boolean;
            };
            export type FieldPresentation = {
                readonly labelKey: string;
                readonly helpKey: string | null;
                readonly options: Record<string, string>;
            };
            export type FieldType =
                | 'string'
                | 'integer'
                | 'numeric'
                | 'boolean'
                | 'object'
                | 'list'
                | 'secret';
            export type Schema = {
                readonly fields: Record<
                    string,
                    Heptapod.Contracts.Schema.Field
                >;
            };
        }
    }
}
declare namespace Spatie {
    namespace LaravelData {
        export type CursorPaginatedDataCollection<TKey, TValue> =
            Illuminate.CursorPaginator<TKey, TValue>;
        export type PaginatedDataCollection<TKey, TValue> =
            Illuminate.LengthAwarePaginator<TKey, TValue>;
    }
}

declare namespace Heptapod {
namespace Addons {
namespace ApplicationHealth {
export type ApplicationHealthComponent = {
readonly id: number,
readonly name: string,
readonly label: string,
readonly status: string,
readonly notificationMessage: string,
readonly shortSummary: string,
readonly meta: Record<number | string, any>,
readonly latest_result: string,
};
export type ApplicationHealthDetailData = {
readonly last_valid_run_id: number | null,
readonly last_valid_at: string | null,
readonly stale: boolean,
readonly components: {
name: string,
label: string,
status: string,
short_summary: string,
latest_result: string,
}[],
readonly more_components: boolean,
readonly attempts: {
run_id: number,
ended_at: string,
result: string,
reason: string | null,
http_status: number | null,
response_time_ms: number | null,
}[],
readonly check_type: Heptapod.Contracts.CheckKey,
};
export type ApplicationHealthEvidence = {
readonly reason: string | null,
readonly http_status: number | null,
readonly redirect_host: string | null,
readonly components: Heptapod.Addons.ApplicationHealth.ApplicationHealthComponent[],
readonly failure_kind: string | null,
};
export type ApplicationHealthMetrics = {
readonly succeeded: number,
readonly warning: number,
readonly failed: number,
readonly finished_at: number | null,
readonly response_time_in_seconds: number | null,
};
}
namespace CertificateHealth {
export type CertificateMetrics = {
readonly issuer: string | null,
readonly fingerprint: string | null,
readonly is_valid: boolean,
};
export type CertificateSnapshot = {
readonly certificate_details: {
fingerprint: string,
issuer: string,
domain: string,
additional_domains: string[],
valid_from: string,
valid_until: string,
signature_algorithm: string,
is_valid: boolean,
} | null,
readonly certificate_checks: {
type: string,
passed: boolean,
label: string,
}[],
readonly certificate_chain_issuers: string[],
readonly check_type: Heptapod.Contracts.CheckKey,
};
export type CertificateTimelineData = {
readonly observations: {
run_id: number,
observed_at: string,
fingerprint: string | null,
issuer: string | null,
valid_from: string | null,
valid_until: string | null,
changed: boolean | null,
count: number,
first_observed_at: string,
names: string[],
history_label?: Heptapod.Contracts.CheckRunPresentationPartData | null,
}[],
readonly check_type: Heptapod.Contracts.CheckKey,
};
}
namespace Crawl {
export type CrawlArtifact = {
site_url: string,
collected_at: string,
settings: Record<string, any>,
urls: Heptapod.Addons.Crawl.CrawlFinding[],
mixed_content: Heptapod.Addons.Crawl.MixedContentFinding[],
truncated: boolean,
observed_urls: string[],
observed_pages: string[],
discovered_urls: string[],
discovery_complete: boolean,
page_cap_reached: boolean,
time_limit_reached: boolean,
resource_cap_reached: boolean,
not_crawlable_reason: Heptapod.Addons.Crawl.NotCrawlableReason | null,
notes: Heptapod.Contracts.CheckRunPresentationPartData[],
start_page_final_url: string | null,
start_page_too_large: boolean,
};
export type CrawlDiscoveryReason = 'request_failed' | 'http_error_pages' | 'page_too_large' | 'bot_protection' | 'rate_limited' | 'nofollow' | 'page_limit' | 'time_limit' | 'resource_limit' | 'invalid_url_limit' | 'start_page_unavailable';
export type CrawlEvidence = {
finding_ids: string[],
truncated: boolean,
unchecked_count: number,
discovery_complete: boolean,
crawl_truncated: boolean,
page_cap: number,
observed_pages: number,
observed_urls: number,
discovered_urls: number,
page_cap_reached: boolean,
time_limit_reached: boolean,
resource_cap_reached: boolean,
comparison_limit_reached: boolean,
notes: Heptapod.Contracts.CheckRunPresentationPartData[],
not_verifiable_count: number,
external_not_verifiable_count: number,
external_not_verifiable_reason: string | null,
external_refused_count: number,
external_refused_reason: string | null,
not_verifiable_reasons: Record<string, number>,
};
export type CrawlFinding = {
crawled_url: string,
relative_crawled_url: string,
status_code: number | null,
found_on_url: string | null,
relative_found_on_url: string | null,
link_text: string | null,
type: string,
error_message: string | null,
internal: boolean,
current_error_message: string | null,
};
export type CrawlFindingsData = {
readonly observed_at: string | null,
readonly time_limit_reached: boolean,
readonly page_cap_reached: boolean,
readonly resource_cap_reached: boolean,
readonly page_cap: number,
readonly findings: {
url: string,
found_on: string | null,
link_text: string | null,
status_code: number | null,
kind: string,
kind_key: string,
internal: boolean,
reason: string | null,
reason_key: string | null,
broken: boolean,
first_seen_in_retained_history_at: string | null,
current_reason_key: string | null,
after_first_complete_crawl: boolean,
}[],
readonly weekly_change: {
current: number,
previous: number,
new: number,
fixed: number,
since: string,
} | null,
readonly snapshot_run: Heptapod.Contracts.RunReference | null,
readonly total_findings: number,
readonly not_verifiable: {
url: string,
reason: string,
status: number | null,
source: string | null,
}[],
readonly total_not_verifiable: number,
readonly check_type: Heptapod.Contracts.CheckKey,
};
export type CrawlMetrics = {
total: number,
internal: number,
external: number,
broken_internal: number,
broken_external: number,
mixed_content: number,
};
export type CrawlSnapshot = {
readonly run_ended_at: string,
readonly discovery_complete: boolean,
readonly crawl_truncated: boolean,
readonly page_cap: number,
readonly observed_pages: number,
readonly observed_urls: number,
readonly discovered_urls: number,
readonly page_cap_reached: boolean,
readonly resource_cap_reached: boolean,
readonly time_limit_reached: boolean,
readonly comparison_limit_reached: boolean,
readonly notes: Heptapod.Contracts.CheckRunPresentationPartData[],
readonly check_type: Heptapod.Contracts.CheckKey,
};
export type MixedContentFinding = {
element_name: string,
mixed_content_url: string,
found_on_url: string,
};
export type NotCrawlableReason = 'authentication_required' | 'non_html' | 'login_redirect' | 'login_page' | 'noindex' | 'nofollow' | 'start_url_excluded' | 'robots_disallowed' | 'bot_protection' | 'rate_limited';
}
namespace Cron {
export type CronMetrics = {
readonly succeeded: number,
readonly warning: number,
readonly failed: number,
readonly pending: number,
};
export type CronTaskResult = 'pending' | 'pingFinished' | 'pingFailed' | 'pingMissed';
export type CronTasksDetailData = {
readonly tasks: {
id: number,
name: string,
result: string,
type: string,
cron_expression: string | null,
timezone: string | null,
frequency_minutes: number | null,
grace_minutes: number,
last_ping_at: string | null,
last_duration_seconds: number | null,
next_due_at: string,
ping_url: string,
days: {
day: string,
result: string,
}[],
}[],
readonly more_tasks: boolean,
readonly history_truncated: boolean,
readonly check_type: Heptapod.Contracts.CheckKey,
};
}
namespace Dns {
export type DnsComparisonData = {
readonly accepted_run_id: number | null,
readonly accepted_at: string | null,
readonly accepted_by: string | null,
readonly accept_would_change_baseline: boolean,
readonly current_issues: {
type: string,
host: string,
record_type: string | null,
}[],
readonly accepted_records: Record<string, Record<string, number | string>[]>,
readonly current_run_id: number | null,
readonly current_records: Record<string, Record<string, number | string>[]>,
readonly changes: Record<string, {
added: Record<string, number | string>[],
removed: Record<string, number | string>[],
}>,
readonly records_truncated: boolean,
readonly timeline: {
run_id: number,
observed_at: string,
diff_summary: string,
initial: boolean,
}[],
readonly check_type: Heptapod.Contracts.CheckKey,
};
export type DnsMetrics = {
readonly record_count: number,
readonly issue_count: number,
readonly changed: boolean,
};
export type DnsSnapshot = {
readonly dns_records: Record<string, number | string>[],
readonly raw_dns_records: Record<string, string[]>,
readonly normalized_records: Record<string, Record<string, number | string>[]>,
readonly issues: {
type: string,
host: string,
record_type: string | null,
nameserver?: string,
reason?: string,
}[],
readonly authoritative_nameservers: string[],
readonly diagnostics: {
type: string,
host: string,
reason: string,
}[],
readonly check_type: Heptapod.Contracts.CheckKey,
};
}
namespace Domain {
export type DomainMetrics = {
readonly expires_at: string | null,
readonly not_supported_reason: string | null,
};
export type DomainSnapshot = {
readonly domain: string,
readonly registrar: string | null,
readonly expires_at: string | null,
readonly registered_at: string | null,
readonly last_changed_at: string | null,
readonly last_updated_in_rdap_db_at: string | null,
readonly created_at: string,
readonly nameservers: string[],
readonly domain_statuses: Record<string, boolean>,
readonly rdap_domain_response: Record<string, unknown> | null,
readonly date_precision: string,
readonly source: string,
readonly server: string,
readonly not_supported_reason: string | null,
readonly check_type: Heptapod.Contracts.CheckKey,
};
}
namespace Lighthouse {
export type LighthouseDetail = {
readonly result: Heptapod.Contracts.RunResult,
readonly metrics: Heptapod.Addons.Lighthouse.LighthouseMetrics | null,
readonly evidence: Heptapod.Addons.Lighthouse.LighthouseEvidence | null,
readonly check_type: Heptapod.Contracts.CheckKey,
};
export type LighthouseEvidence = {
readonly issues: {
category: string,
actualScore: number,
notificationThreshold: number,
}[],
readonly score_drops: {
category: string,
previousScore: number,
actualScore: number,
}[],
readonly performed_on_checker_server: string,
readonly device_emulation: string | null,
readonly load_timed_out: boolean,
};
export type LighthouseFailureCode = 'audit_budget_exceeded' | 'page_hung' | 'page_crashed' | 'no_content' | 'not_html' | 'page_load_failed' | 'report_unscored' | 'report_too_large' | 'audit_incomplete';
export type LighthouseHistoryData = {
readonly last_successful_run_id: number | null,
readonly last_successful_at: string | null,
readonly last_successful_metrics: Heptapod.Addons.Lighthouse.LighthouseMetrics | null,
readonly last_successful_device: string | null,
readonly scores: {
day: string,
performance: number | null,
accessibility: number | null,
best_practices: number | null,
seo: number | null,
}[],
readonly check_type: Heptapod.Contracts.CheckKey,
};
export type LighthouseMetrics = {
readonly performance_score: number,
readonly accessibility_score: number,
readonly best_practices_score: number,
readonly seo_score: number,
readonly progressive_web_app_score: number | null,
readonly first_contentful_paint_in_ms: number | null,
readonly speed_index_in_ms: number | null,
readonly largest_contentful_paint_in_ms: number | null,
readonly time_to_interactive_in_ms: number | null,
readonly total_blocking_time_in_ms: number | null,
readonly cumulative_layout_shift: number | null,
};
}
namespace Performance {
export type PerformanceMetrics = {
readonly average_response_time_in_ms: number,
readonly previous_average_response_time_in_ms: number | null,
readonly change_percentage: number | null,
readonly sample_count: number,
readonly previous_sample_count: number,
readonly consecutive_warning_samples: number,
readonly consecutive_good_samples: number,
readonly consecutive_improved_samples: number,
readonly consecutive_normal_samples: number,
};
}
namespace Sitemap {
export type SitemapMetrics = {
readonly totalIssuesCount: number,
readonly totalUrlCount: number,
readonly hasIssues: boolean,
};
export type SitemapSnapshot = {
readonly issue_summary: string,
readonly issue_summary_params: Record<string, number | string>,
readonly issue_counts: Record<string, number> | null,
readonly checkUrl: string,
readonly totalIssuesCount: number,
readonly totalUrlCount: number,
readonly hasIssues: boolean,
readonly issues: {
url: string,
type: string,
kind?: string,
status?: number | null,
xml_reason?: string,
xml_line?: number,
xml_element?: string,
}[],
readonly sitemapIndexes: {
url: string,
issues: {
url: string,
type: string,
kind?: string,
status?: number | null,
xml_reason?: string,
xml_line?: number,
xml_element?: string,
}[],
}[],
readonly sitemaps: {
url: string,
urlCount: number,
checkedReachabilityOfAllUrls: boolean,
issuesCount: number,
issues: {
url: string,
type: string,
kind?: string,
status?: number | null,
xml_reason?: string,
xml_line?: number,
xml_element?: string,
}[],
}[],
readonly notes: string[],
readonly export_run: Heptapod.Contracts.RunReference | null,
readonly not_verifiable: {
url: string,
reason: string,
status: number | null,
}[],
readonly check_type: Heptapod.Contracts.CheckKey,
};
export type SitemapXmlReason = 'empty_body' | 'html' | 'syntax' | 'unclosed_tag' | 'mismatched_tag' | 'invalid_encoding' | 'doctype' | 'root' | 'namespace' | 'child' | 'missing_loc' | 'duplicate_loc' | 'invalid_loc' | 'no_entries' | 'gzip';
}
namespace Uptime {
export type UptimeEvidence = {
readonly status_code: number | null,
readonly issues: string[],
readonly failure_kind: string | null,
readonly timeout_seconds: number | null,
readonly effective_url: string | null,
readonly expected_url: string | null,
readonly required_string: string | null,
readonly absent_string: string | null,
};
export type UptimeHistoryData = {
readonly runs: {
run_id: number,
ended_at: string,
result: string,
status_code: number | null,
status_text: string | null,
reasons: string[],
total_time_ms: number | null,
}[],
readonly recent_failures: {
run_id: number,
ended_at: string,
result: string,
status_code: number | null,
status_text: string | null,
reasons: string[],
total_time_ms: number | null,
}[],
readonly check_type: Heptapod.Contracts.CheckKey,
};
export type UptimeMetrics = {
readonly dns_time_in_seconds: number | null,
readonly tcp_time_in_seconds: number | null,
readonly ssl_handshake_time_in_seconds: number | null,
readonly remote_server_processing_time_in_seconds: number | null,
readonly download_time_in_seconds: number | null,
readonly total_time_in_seconds: number | null,
readonly curl: Record<string, number | null>,
readonly date: string,
};
}
}
namespace Checks {
namespace Execution {
export type RunOrigin = 'scheduled' | 'manual' | 'system';
}
}
namespace Contracts {
export type AddonEvent = {
readonly presentationParams: Record<string | number, any> | null,
readonly type: string,
readonly dedupeKey: string,
readonly version: number,
readonly payload: Record<string, any>,
readonly subject: Heptapod.Contracts.SuppressionSubject | null,
};
export type AvailabilityFailure = 'tls' | 'http_5xx';
export type CheckDefinition = {
readonly key: Heptapod.Contracts.CheckKey,
readonly labelKey: string,
readonly icon: string,
readonly queue: string,
readonly timeout: number,
readonly defaultInterval: number,
readonly defaultFailureThreshold: number,
readonly executionMode: Heptapod.Contracts.ExecutionMode,
readonly configSchema: Heptapod.Contracts.Schema.Schema,
readonly metricSchema: Heptapod.Contracts.Schema.Schema,
readonly emittedEvents: string[],
readonly requiresHttps: boolean,
readonly recommended: boolean,
readonly detailDataClass: string | null,
readonly minimumIncreaseInMs: number | null,
};
export type CheckDetailData = Heptapod.Addons.CertificateHealth.CertificateSnapshot & {
readonly check_type: 'certificate_health',
} | Heptapod.Addons.Crawl.CrawlSnapshot & {
readonly check_type: 'broken_links',
} | Heptapod.Addons.Crawl.CrawlSnapshot & {
readonly check_type: 'mixed_content',
} | Heptapod.Addons.Dns.DnsSnapshot & {
readonly check_type: 'dns',
} | Heptapod.Addons.Domain.DomainSnapshot & {
readonly check_type: 'domain',
} | Heptapod.Addons.Lighthouse.LighthouseDetail & {
readonly check_type: 'lighthouse',
} | Heptapod.Addons.Sitemap.SitemapSnapshot & {
readonly check_type: 'sitemap',
};
export type CheckError = {
readonly class: string,
readonly message: string,
};
export type CheckHistoryData = Heptapod.Addons.ApplicationHealth.ApplicationHealthDetailData & {
readonly check_type: 'application_health',
} | Heptapod.Addons.CertificateHealth.CertificateTimelineData & {
readonly check_type: 'certificate_health',
} | Heptapod.Addons.Crawl.CrawlFindingsData & {
readonly check_type: 'broken_links',
} | Heptapod.Addons.Crawl.CrawlFindingsData & {
readonly check_type: 'mixed_content',
} | Heptapod.Addons.Cron.CronTasksDetailData & {
readonly check_type: 'cron',
} | Heptapod.Addons.Dns.DnsComparisonData & {
readonly check_type: 'dns',
} | Heptapod.Addons.Lighthouse.LighthouseHistoryData & {
readonly check_type: 'lighthouse',
} | Heptapod.Addons.Uptime.UptimeHistoryData & {
readonly check_type: 'uptime',
};
export type CheckKey = 'uptime' | 'performance' | 'certificate_health' | 'broken_links' | 'mixed_content' | 'dns' | 'domain' | 'lighthouse' | 'cron' | 'application_health' | 'sitemap';
export type CheckOutcome = {
readonly result: Heptapod.Contracts.RunResult,
readonly summary: string,
readonly summaryParams: Record<string, number | string | boolean | null>,
readonly metrics: Record<string, unknown> | null,
readonly evidence: Record<string, unknown> | null,
readonly events: Heptapod.Contracts.AddonEvent[],
readonly error: Heptapod.Contracts.CheckError | null,
readonly incomplete: boolean,
readonly confirmedIncompleteFailure: boolean,
readonly availabilityFailure: Heptapod.Contracts.AvailabilityFailure | null,
readonly verifiedNothing: boolean,
};
export type CheckResult = 'pending' | 'succeeded' | 'warning' | 'failed' | 'errored-or-timed-out';
export type CheckRunPresentationData = {
readonly more_reasons: number,
readonly headline_count: number,
readonly parts: Heptapod.Contracts.CheckRunPresentationPartData[],
readonly explanation: Heptapod.Contracts.CheckRunPresentationPartData | null,
readonly history_label: Heptapod.Contracts.CheckRunPresentationPartData | null,
readonly names_check: boolean,
readonly headline_limit: number,
readonly changes: Heptapod.Contracts.CheckRunPresentationPartData[],
readonly details: Heptapod.Contracts.CheckRunPresentationPartData[],
};
export type CheckRunPresentationPartData = {
readonly key: string,
readonly params: Record<string, string | number | boolean | null>,
readonly labels: Record<string, string>,
readonly shortKey: string | null,
readonly untrusted: Record<string, string>,
readonly trusted: string[],
readonly lists: Record<string, string[]>,
};
export type DomainRegistrationSource = 'rdap' | 'whois';
export type ExecutionMode = 'poll' | 'push' | 'derived';
export type NoResultReason = 'performance_no_samples' | 'performance_uptime_interval_too_long' | 'mixed_content_start_page_not_scanned' | 'cron_tasks_pending';
export type PreviousResult = {
readonly run: Heptapod.Contracts.RunReference,
readonly result: Heptapod.Contracts.RunResult,
readonly startedAt: string | null,
readonly summary: string,
readonly summaryParams: Record<string, number | string | boolean | null>,
readonly metrics: Record<string, any> | null,
};
export type RunPhase = 'scheduled' | 'queued' | 'running' | 'done' | 'failed' | 'late' | 'skipped' | 'cancelled';
export type RunReference = {
readonly id: number,
readonly endedAt: string,
};
export type RunResult = 'succeeded' | 'warning' | 'failed' | 'errored-or-timed-out';
export type SuppressionComponent = 'cron' | 'application_health';
export type SuppressionDecision = {
readonly suppressed: boolean,
readonly reason: string | null,
readonly evaluatedAt: string,
readonly since: string | null,
};
export type SuppressionSubject = {
readonly checkId: number,
readonly componentType: Heptapod.Contracts.SuppressionComponent | null,
readonly componentId: number | null,
};
export type TlsFailureReason = 'name_mismatch' | 'expired' | 'self_signed' | 'incomplete_chain' | 'not_yet_valid' | 'date_invalid' | 'handshake';
namespace Findings {
export type AvailableFindingsSnapshot = {
readonly check_id: number,
readonly type: Heptapod.Contracts.CheckKey,
readonly snapshot_ref: string,
readonly run: Heptapod.Contracts.RunReference,
readonly observed_at: string,
readonly coverage: Heptapod.Contracts.Findings.FindingsCoverage,
readonly supported_filters: Heptapod.Contracts.Findings.FindingStatus[],
readonly supported_baseline_comparison: boolean,
readonly dataset_version: number,
};
export type FindingCause = 'request_failed' | 'access_refused' | 'login_required' | 'bot_protection' | 'rate_limited' | 'timeout' | 'connect_failed' | 'connection_reset' | 'dns_unresolved' | 'too_many_redirects' | 'tls_expired' | 'tls_not_yet_valid' | 'tls_name_mismatch' | 'tls_untrusted' | 'tls_handshake_failed' | 'private_target' | 'invalid_url' | 'page_too_large' | 'cause_not_recorded';
export type FindingStatus = 'broken' | 'not_verifiable' | 'unchecked' | 'coverage_limit';
export type FindingsCoverage = {
readonly discovery_complete: boolean | null,
readonly crawl_truncated: boolean | null,
readonly page_cap_reached: boolean | null,
readonly resource_cap_reached: boolean | null,
readonly time_limit_reached: boolean | null,
readonly comparison_limit_reached: boolean | null,
readonly stored_rows_complete: boolean | null,
readonly stored_not_verifiable_complete: boolean | null,
readonly unchecked_rows_available: boolean,
readonly legacy: boolean,
readonly counters: Record<string, number | null> | null,
readonly limits: Record<string, number | null> | null,
};
export type FindingsFilter = {
readonly status: Heptapod.Contracts.Findings.FindingStatus | null,
readonly only_new_since: boolean,
};
export type FindingsPage = {
readonly snapshot: Heptapod.Contracts.Findings.AvailableFindingsSnapshot,
readonly baseline: Heptapod.Contracts.Findings.AvailableFindingsSnapshot | null,
readonly baseline_status: string,
readonly rows: Heptapod.Contracts.Findings.FindingsRow[],
readonly next_position: Heptapod.Contracts.Findings.FindingsPosition | null,
readonly has_more: boolean,
};
export type FindingsPosition = {
readonly group: Heptapod.Contracts.Findings.FindingsSourceGroup,
readonly position: number,
};
export type FindingsRow = {
readonly row_ref: string,
readonly finding_identity: string,
readonly status: Heptapod.Contracts.Findings.FindingStatus,
readonly issue_code: string | null,
readonly http_status: number | null,
readonly kind: string | null,
readonly internal: boolean | null,
readonly url: string,
readonly found_on: string | null,
readonly link_text: string | null,
readonly error_message: string | null,
readonly cause: Heptapod.Contracts.Findings.FindingCause | null,
readonly first_seen_at: string | null,
readonly new_since_opening: boolean | null,
readonly new_since_baseline: boolean | null,
readonly carried_forward: boolean | null,
readonly source_observed_at: string | null,
readonly current_observation_cause: Heptapod.Contracts.Findings.FindingCause | null,
readonly field_truncation: string[],
};
export type FindingsSourceGroup = 'rows' | 'mixed' | 'verification';
export type FindingsUnavailableReason = 'not_retained' | 'no_observation' | 'comparison_limit' | 'legacy_metadata_missing' | 'incomparable';
export type FindingsUnavailableStatus = 'evidence_expired' | 'unsupported' | 'no_dataset';
export type UnavailableFindingsSnapshot = {
readonly status: Heptapod.Contracts.Findings.FindingsUnavailableStatus,
readonly check_id: number,
readonly type: Heptapod.Contracts.CheckKey,
readonly requested_run: Heptapod.Contracts.RunReference,
readonly reason: Heptapod.Contracts.Findings.FindingsUnavailableReason,
readonly coverage: Heptapod.Contracts.Findings.FindingsCoverage | null,
};
}
namespace Http {
export type AutomatedCheckBlockReason = 'bot_protection' | 'rate_limited';
export type ConnectionFailureReason = 'connect_failed' | 'reset';
export type FetchFailureKind = 'timeout' | 'too_large' | 'tls' | 'dns' | 'connect' | 'redirect_blocked' | 'too_many_redirects' | 'private_target' | 'http_status' | 'invalid_request';
export type RedirectPolicy = 'off' | 'operator' | 'untrusted';
}
namespace Mail {
export type AlertMailEvent = 'incident.opened' | 'incident.still_down' | 'incident.resolved' | 'warning' | 'changed' | 'check-error' | 'incident.cause_changed';
export type MailDesign = 'branded' | 'plain';
export type MailTone = 'down' | 'failed' | 'warn' | 'ok' | 'error' | 'info' | 'neutral';
}
namespace Notifications {
export type AlertMessage = {
readonly eventId: number,
readonly severity: Heptapod.Contracts.Notifications.AlertSeverity,
readonly titleKey: string,
readonly titleParams: Record<string, string | number>,
readonly summaryKey: string,
readonly summaryParams: Record<string, string | number>,
readonly siteId: number | null,
readonly siteLabel: string,
readonly siteUrl: string,
readonly checkType: string | null,
readonly since: string,
readonly downtimeSeconds: number | null,
readonly cause: string | null,
readonly link: string | null,
readonly isTest: boolean,
readonly clientName: string | null,
readonly checkTypes: string[] | null,
readonly affectedChecks: string[],
readonly recheckingChecks: string[],
};
export type AlertSeverity = 'down' | 'recovered' | 'warning' | 'error' | 'info';
export type DeliveryResult = {
readonly status: Heptapod.Contracts.Notifications.DeliveryState,
readonly reason: string | null,
readonly providerMessageId: string | null,
readonly diagnosis: string | null,
};
export type DeliveryState = 'pending' | 'sending' | 'sent' | 'failed' | 'logged';
}
namespace OhDear {
export type CheckData = {
readonly id: number,
readonly type: Heptapod.Contracts.CheckKey,
readonly label: string,
readonly enabled: boolean,
readonly latest_run_ended_at: string | null,
readonly latest_run_result: Heptapod.Contracts.CheckResult,
readonly summary: string | null,
readonly settings: object,
readonly active_snooze: Heptapod.Contracts.OhDear.SnoozeData | null,
readonly average_response_time_in_ms?: number | null,
readonly unavailable_reason: string | null,
};
export type CheckRunData = {
readonly result: string,
readonly id: number,
readonly check_id: number,
readonly check_type: Heptapod.Contracts.CheckKey,
readonly started_at: string | null,
readonly ended_at: string,
readonly ohdear_url: string,
};
export type CheckSummaryData = {
readonly result: string | null,
readonly summary: string,
};
export type MonitorData = {
readonly type: string,
readonly id: number,
readonly team_id: number,
readonly url: string,
readonly uses_https: boolean,
readonly sort_url: string,
readonly label: string,
readonly group_name: string,
readonly tags: string[],
readonly description: string | null,
readonly notes: string | null,
readonly latest_run_date: string | null,
readonly summarized_check_result: Heptapod.Contracts.CheckResult,
readonly checks: Heptapod.Contracts.OhDear.CheckData[],
readonly uptime_check_settings: Record<string, any>,
readonly badge_id: string,
readonly marked_for_deletion_at: string | null,
readonly created_at: string,
readonly updated_at: string,
readonly certificate_health_check_settings?: Record<string, any> | null,
readonly broken_links_check_settings?: Record<string, any> | null,
readonly dns_check_settings?: Record<string, any> | null,
readonly lighthouse_check_settings?: Record<string, any> | null,
readonly application_health_check_settings?: Record<string, any> | null,
readonly domain_check_settings?: Record<string, any> | null,
readonly performance_check_settings?: Record<string, any> | null,
readonly sitemap_check_settings?: Record<string, any> | null,
readonly crawler_headers?: {
name: string,
value: string,
}[] | null,
readonly send_report_to_emails?: string[] | null,
readonly include_check_types_in_report?: Heptapod.Contracts.CheckKey[] | null,
};
export type SnoozeData = {
readonly id: number,
readonly starts_at: string,
readonly ends_at: string,
readonly snoozed_by: string,
readonly unsnoozed_by: string | null,
readonly source: string,
readonly created_at: string,
};
}
namespace Schema {
export type Field = {
readonly type: Heptapod.Contracts.Schema.FieldType,
readonly rules: string[],
readonly properties: Record<string, Heptapod.Contracts.Schema.Field>,
readonly items: Heptapod.Contracts.Schema.Field | null,
readonly presentation: Heptapod.Contracts.Schema.FieldPresentation | null,
readonly additionalProperties: boolean,
readonly targetUrl: boolean,
readonly default?: Record<string | number, any> | string | number | boolean | null,
readonly uppercase: boolean,
readonly trimmedStrings: boolean,
};
export type FieldPresentation = {
readonly labelKey: string,
readonly helpKey: string | null,
readonly options: Record<string, string>,
};
export type FieldType = 'string' | 'integer' | 'numeric' | 'boolean' | 'object' | 'list' | 'secret';
export type Schema = {
readonly fields: Record<string, Heptapod.Contracts.Schema.Field>,
};
}
}
namespace Data {
namespace Import {
export type ImportPlanData = {
readonly dry_run: boolean,
readonly teams: {
id: number,
name: string,
}[],
readonly rows: Heptapod.Data.Import.ImportPlanRowData[],
readonly remote_error: string | null,
};
export type ImportPlanRowData = {
readonly source_id: string,
readonly url: string | null,
readonly label: string | null,
readonly team_id: number | null,
readonly tags: string[],
readonly operation: Heptapod.Enums.ImportOperation,
readonly checks: string[],
readonly disabled_checks: string[],
readonly notices: string[],
readonly details: string,
readonly site_id: number | null,
readonly client_name: string | null,
readonly inherits_alert_timing: boolean | null,
};
}
namespace Ui {
export type ActivityFilterData = {
readonly q: string,
readonly client: number | null,
readonly check: Heptapod.Contracts.CheckKey | null,
readonly period: string,
readonly before: number | null,
readonly after: number | null,
};
export type ActivityGroupData = {
readonly id: number,
readonly latest: Heptapod.Data.Ui.EventData,
readonly event_count: number,
readonly started_at: string,
readonly ended_at: string,
readonly first_state: Heptapod.Contracts.CheckResult | null,
readonly second_state: Heptapod.Contracts.CheckResult | null,
readonly current_state: Heptapod.Contracts.CheckResult | null,
};
export type ActivityPageData = {
readonly events: Heptapod.Data.Ui.ActivityGroupData[],
readonly older_cursor: number | null,
readonly newer_cursor: number | null,
readonly retention_days: number,
readonly clients: Heptapod.Data.Ui.ClientData[],
readonly filters: Heptapod.Data.Ui.ActivityFilterData,
readonly expanded_id: number | null,
readonly expanded_events: Heptapod.Data.Ui.EventData[],
readonly event_page: number,
};
export type AddSitePageData = {
readonly clients: Heptapod.Data.Ui.ClientData[],
readonly checks: Heptapod.Data.Ui.CheckOptionData[],
readonly can_create_site: boolean,
readonly selected_client_id: number | null,
readonly timezones: string[],
};
export type AlertSensitivityData = {
readonly alert_after_minutes: number,
readonly alert_recovery_checks: number,
readonly inherited: boolean,
readonly preset: Heptapod.Enums.AlertSensitivity | null,
};
export type AlertSensitivitySettingsPageData = {
readonly saved: Heptapod.Data.Ui.AlertSensitivityData,
readonly presets: Heptapod.Data.Ui.AlertSensitivityData[],
readonly bounds: {
minutes_min: number,
minutes_max: number,
stored_minutes_min: number,
stored_minutes_max: number,
recovery_min: number,
recovery_max: number,
},
};
export type ApiTokenSettingsData = {
readonly tokens: {
id: number,
name: string,
abilities: string[],
client_id: number | null,
client_name: string | null,
created_at: string | null,
last_used_at: string | null,
expires_at: string | null,
}[],
readonly abilities: string[],
readonly presets: {
read: string[],
write: string[],
manage: string[],
'agent-read': string[],
},
readonly clients: {
id: number,
name: string,
short_name: string | null,
accent_color: string | null,
avatar: Heptapod.Data.Ui.ClientAvatarData,
is_internal: boolean,
}[],
readonly timezone: string,
};
export type ApiTokensPageData = {
readonly tokensPage: Heptapod.Data.Ui.ApiTokenSettingsData,
};
export type ChannelTypeData = {
readonly key: string,
readonly label_key: string,
readonly description_key: string,
readonly config_schema: Heptapod.Contracts.Schema.Schema,
};
export type CheckFreshnessData = {
readonly state: 'fresh' | 'not_fresh' | 'not_expected',
readonly awaiting_result_since: string | null,
readonly not_fresh_since: string | null,
readonly threshold_seconds: number,
readonly lost_attempts: number,
};
export type CheckOptionData = {
readonly key: Heptapod.Contracts.CheckKey,
readonly label_key: string,
readonly icon: string,
readonly recommended: boolean,
readonly requires_https: boolean,
readonly interval_seconds: number,
readonly config_schema: Heptapod.Contracts.Schema.Schema,
readonly task_definition_schema: Heptapod.Contracts.Schema.Schema | null,
readonly unavailable_reason: string | null,
};
export type CheckRunData = {
readonly id: number,
readonly result: Heptapod.Contracts.CheckResult,
readonly started_at: string | null,
readonly ended_at: string,
readonly summary: string | null,
readonly summary_params: Record<string, string | number | boolean | null>,
readonly presentation: Heptapod.Contracts.CheckRunPresentationData | null,
};
export type CheckRunStateData = {
readonly check_id: number,
readonly phase: Heptapod.Contracts.RunPhase,
readonly attempt_id: number | null,
readonly queue: string | null,
readonly queue_position: number | null,
readonly progress_n: number | null,
readonly progress_total: number | null,
readonly phase_label: string | null,
readonly started_at: string | null,
readonly actor: string | null,
readonly due_at: string | null,
readonly can_cancel: boolean,
readonly requested_at: string | null,
};
export type CheckScheduleData = {
readonly interval_seconds: number,
readonly next_due_at: string | null,
readonly last_started_at: string | null,
readonly last_finished_at: string | null,
readonly last_duration_ms: number | null,
readonly run: Heptapod.Data.Ui.CheckRunStateData,
readonly freshness: Heptapod.Data.Ui.CheckFreshnessData,
readonly last_loss: { cause: 'lease_lost' | 'did_not_start' | 'worker_out_of_memory' | 'timed_out' } | null,
};
export type CheckSettingsData = {
readonly site_id: number,
readonly check_id: number,
readonly check_key: Heptapod.Contracts.CheckKey,
readonly label_key: string,
readonly enabled: boolean,
readonly interval_seconds: number,
readonly config_schema: Heptapod.Contracts.Schema.Schema,
readonly settings: object,
readonly can_update: boolean,
};
export type CheckStripData = {
readonly state: Heptapod.Enums.SiteStatus,
readonly freshness_suppression_label_key: string | null,
readonly dependency_headline_key: string | null,
readonly id: number,
readonly key: Heptapod.Contracts.CheckKey,
readonly label_key: string,
readonly icon_id: string,
readonly enabled: boolean,
readonly result: Heptapod.Contracts.CheckResult,
readonly open_incident_id: number | null,
readonly open_incident_started_at: string | null,
readonly summary: string | null,
readonly summary_params: Record<string, string | number | boolean | null>,
readonly checked_at: string | null,
readonly snoozed_until: string | null,
readonly meta_label: string | null,
readonly meta: string | null,
readonly unavailable_reason: string | null,
readonly run: Heptapod.Data.Ui.CheckRunStateData | null,
readonly open_incident_requires_complete_recovery: boolean,
readonly uptime_parent_incident_id: number | null,
readonly affected_checks: string[],
readonly uptime_recheck_after: string | null,
readonly held_after_recovery: boolean,
readonly next_run_at: string | null,
readonly presentation: Heptapod.Contracts.CheckRunPresentationData | null,
readonly not_fresh: boolean,
readonly freshness_suppressed_by: Heptapod.Enums.FreshnessSuppression | null,
readonly pending_recheck: boolean,
};
export type CheckTabPageData = {
readonly site: Heptapod.Data.Ui.SiteRowData,
readonly header: Heptapod.Data.Ui.SiteIdentityData,
readonly check: Heptapod.Data.Ui.CheckStripData,
readonly can_run: boolean,
readonly schedule: Heptapod.Data.Ui.CheckScheduleData,
readonly failure_threshold: number,
readonly runs: Heptapod.Data.Ui.CheckRunData[],
readonly metrics: Heptapod.Data.Ui.MetricSeriesData[],
readonly check_settings: Heptapod.Data.Ui.CheckSettingsData,
readonly detail: Heptapod.Contracts.CheckDetailData | null,
readonly response_times: Heptapod.Data.Ui.ResponseTimeSeriesData[],
readonly phase_series: Heptapod.Data.Ui.MetricSeriesData[],
readonly response_p95_hourly: Heptapod.Data.Ui.MetricSeriesData | null,
readonly client: Heptapod.Data.Ui.ClientData,
readonly history: Heptapod.Contracts.CheckHistoryData | null,
readonly run_all_checks: Heptapod.Data.Ui.RunAllCheckData[],
readonly maintenance: Heptapod.Data.Ui.MaintenanceSiteHeaderData | null,
readonly performance_noise: Heptapod.Data.Ui.PerformanceNoiseData | null,
readonly performance_unavailable: Heptapod.Contracts.CheckRunPresentationPartData | null,
readonly freshness_suppressed_by: Heptapod.Enums.FreshnessSuppression | null,
readonly freshness_suppressed_since: string | null,
readonly freshness_latest_error_at: string | null,
readonly freshness_suppression: Heptapod.Contracts.CheckRunPresentationPartData | null,
readonly mail_design: Heptapod.Contracts.Mail.MailDesign,
};
export type ClientAvatarData = {
readonly url: string | null,
readonly backing: Heptapod.Enums.SiteIconBacking,
readonly source: Heptapod.Enums.ClientAvatarSource,
readonly site_id: number | null,
readonly site_host: string | null,
};
export type ClientAvatarSiteData = {
readonly id: number,
readonly name: string,
readonly host: string | null,
};
export type ClientData = {
readonly id: number,
readonly name: string,
readonly locale: string,
readonly timezone: string,
readonly short_name: string | null,
readonly contact_name: string | null,
readonly accent_color: string | null,
readonly archived_at: string | null,
readonly is_internal: boolean,
readonly avatar: Heptapod.Data.Ui.ClientAvatarData,
readonly avatar_site_id: number | null,
};
export type ClientDetailPageData = {
readonly client: Heptapod.Data.Ui.ClientData,
readonly summary: Heptapod.Data.Ui.DashboardTilesData,
readonly sites: Heptapod.Data.Ui.SiteRowData[],
readonly pagination: Heptapod.Data.Ui.PaginationData,
readonly incidents: Heptapod.Data.Ui.IncidentData[],
readonly can_manage: boolean,
};
export type ClientMemberData = {
readonly name: string,
readonly role: string,
};
export type ClientMessageRowData = {
readonly id: number,
readonly event_id: number,
readonly at: string,
readonly kind: 'findings' | 'incident' | 'alert',
readonly subject: string,
readonly origin: string,
readonly actor: string | null,
readonly channel: string,
readonly address: string | null,
readonly state: Heptapod.Enums.DeliveryOutcome,
readonly reason: string | null,
readonly attachment: boolean,
readonly resend: { kind: 'findings' | 'incident'; site_id: number | null; check_id: number | null; incident_id: number | null; note: string; attach: boolean; design: Heptapod.Contracts.Mail.MailDesign; one_off: string | null } | null,
};
export type ClientNotificationsPageData = {
readonly settings: Heptapod.Data.Ui.NotificationSettingsPageData,
readonly client: Heptapod.Data.Ui.ClientData,
};
export type ClientRowData = {
readonly client: Heptapod.Data.Ui.ClientData,
readonly summary: Heptapod.Data.Ui.DashboardTilesData,
};
export type ClientSettingsPageData = {
readonly client: Heptapod.Data.Ui.ClientData | null,
readonly members: Heptapod.Data.Ui.ClientMemberData[],
readonly avatar_sites: Heptapod.Data.Ui.ClientAvatarSiteData[],
readonly timezones: string[],
};
export type ClientsIndexPageData = {
readonly clients: Heptapod.Data.Ui.ClientRowData[],
readonly pagination: Heptapod.Data.Ui.PaginationData,
readonly search: string | null,
readonly state: string,
readonly can_manage: boolean,
};
export type CurrentRunData = {
readonly check_id: number,
readonly site_id: number,
readonly client_id: number,
readonly site_name: string,
readonly check_type: Heptapod.Contracts.CheckKey,
readonly run: Heptapod.Data.Ui.CheckRunStateData,
readonly icon_url: string | null,
readonly icon_backing: Heptapod.Enums.SiteIconBacking,
};
export type DashboardTilesData = {
readonly sites: number,
readonly down: number,
readonly check_errors: number,
readonly warning: number,
readonly pending: number,
readonly paused: number,
readonly open_incidents: number,
readonly uptime: number | null,
readonly certificates_expiring: number,
readonly cron_jobs_failing: number,
readonly ok: number,
readonly failed: number,
readonly maintenance: number,
readonly disabled: number,
readonly unavailable: number,
readonly certificates_expiring_30_days: number | null,
readonly domains_expiring_30_days: number | null,
readonly scheduled_tasks: number | null,
readonly scheduled_tasks_overdue_pings: number | null,
};
export type EventData = {
readonly id: number,
readonly severity: Heptapod.Contracts.Notifications.AlertSeverity,
readonly site_id: number | null,
readonly site_name: string,
readonly incident_id: number | null,
readonly check: Heptapod.Contracts.CheckKey | null,
readonly type: string,
readonly time: string,
readonly message: string,
readonly message_params: Record<string, string | number | boolean | null>,
readonly duration_seconds: number | null,
readonly client_name: string,
readonly icon_url: string | null,
readonly icon_backing: Heptapod.Enums.SiteIconBacking,
readonly client_id: number | null,
readonly changes: Heptapod.Contracts.CheckRunPresentationPartData[],
readonly run_id: number | null,
};
export type FinishedRunData = {
readonly id: number,
readonly check_id: number,
readonly site_id: number,
readonly site_name: string,
readonly check_type: Heptapod.Contracts.CheckKey,
readonly result: Heptapod.Contracts.RunResult,
readonly ended_at: string,
readonly duration_ms: number | null,
readonly summary: string | null,
readonly summary_params: Record<string, number | string | boolean | null>,
readonly presentation: Heptapod.Contracts.CheckRunPresentationData | null,
readonly icon_url: string | null,
readonly icon_backing: Heptapod.Enums.SiteIconBacking,
readonly attempt_id: number | null,
};
export type IconRefreshResultData = {
readonly key: string,
readonly params: Record<string, string | number | boolean | null>,
};
export type ImportOhDearPageData = {
readonly clients: {
id: number,
name: string,
is_internal: boolean,
}[],
};
export type ImportOhDearRunPageData = {
readonly importRun: {
id: string,
status: Heptapod.Enums.ImportRunStatus,
expected: number,
attempts: number,
processed: number,
rows: Record<string, any>[],
remote_error: string | null,
error: string | null,
failed: number,
failed_earlier: number,
started_at: string | null,
finished_at: string | null,
can_retry: boolean,
cron: {
site: string,
site_id: number,
tasks: {
name: string,
ping_url: string,
}[],
}[],
},
};
export type IncidentData = {
readonly dependency_headline_key: string | null,
readonly id: number,
readonly site_id: number,
readonly site_name: string,
readonly client_id: number,
readonly check: Heptapod.Contracts.CheckKey,
readonly cause: Heptapod.Enums.IncidentCause,
readonly duration_seconds: number,
readonly started_at: string,
readonly resolved_at: string | null,
readonly error: string | null,
readonly snoozed_until: string | null,
readonly summary: string | null,
readonly summary_params: Record<string, string | number | boolean | null>,
readonly icon_url: string | null,
readonly uptime_parent_incident_id: number | null,
readonly affected_checks: string[],
readonly uptime_recheck_after: string | null,
readonly held_after_recovery: boolean,
readonly next_run_at: string | null,
readonly icon_backing: Heptapod.Enums.SiteIconBacking,
readonly presentation: Heptapod.Contracts.CheckRunPresentationData | null,
};
export type IncidentDeliveryData = {
readonly address: string,
readonly event_type: string,
readonly state: Heptapod.Enums.DeliveryOutcome,
readonly diagnosis: string | null,
readonly sent_at: string | null,
readonly scheduled_at: string,
readonly event_id: number,
readonly id: number,
readonly digest: boolean,
readonly can_preview: boolean,
readonly event_label: string,
readonly timeline_time: string | null,
readonly sent_delay_seconds: number | null,
readonly retry_count: number,
};
export type IncidentDetailPageData = {
readonly site: Heptapod.Data.Ui.SiteRowData,
readonly header: Heptapod.Data.Ui.SiteIdentityData,
readonly client: Heptapod.Data.Ui.ClientData,
readonly incident: Heptapod.Data.Ui.IncidentData,
readonly runs: Heptapod.Data.Ui.CheckRunData[],
readonly internal_note: string | null,
readonly note_author: string | null,
readonly note_updated_at: string | null,
readonly other_deliveries: Heptapod.Data.Ui.IncidentDeliveryData[],
readonly evidence: Heptapod.Data.Ui.IncidentEvidenceData | null,
readonly alert_preview_available: boolean,
readonly maintenance: Heptapod.Data.Ui.MaintenanceSiteHeaderData | null,
readonly opened_during_maintenance: boolean,
readonly opening_presentation: Heptapod.Contracts.CheckRunPresentationData | null,
readonly current_presentation: Heptapod.Contracts.CheckRunPresentationData | null,
readonly current_evidence: Heptapod.Contracts.CheckDetailData | null,
readonly current_findings: Heptapod.Contracts.CheckHistoryData | null,
readonly timeline: Heptapod.Data.Ui.IncidentTimelineEntryData[],
readonly related_incidents: Heptapod.Data.Ui.IncidentData[],
readonly expanded_notifications: Heptapod.Data.Ui.IncidentNotificationPageData | null,
readonly can_run: boolean,
readonly can_snooze: boolean,
readonly can_unsnooze: boolean,
readonly opening_delivery_explanation: string | null,
readonly mail_design: Heptapod.Contracts.Mail.MailDesign,
};
export type IncidentEvidenceData = {
readonly facts: Record<string, any>,
readonly summary: string | null,
readonly summary_params: Record<string, string | number | boolean | null>,
readonly opened_at: string | null,
readonly recovered_at: string | null,
readonly recovery_summary: string | null,
readonly recovery_summary_params: Record<string, string | number | boolean | null>,
readonly retained_good_results_since_recovery: number | null,
readonly good_results_capped: boolean,
};
export type IncidentFilterData = {
readonly status: string,
readonly cause: Heptapod.Enums.IncidentCause | null,
readonly check: Heptapod.Contracts.CheckKey | null,
readonly client_id: number | null,
readonly started_after: string | null,
readonly started_before: string | null,
readonly period: string,
};
export type IncidentNotificationGroupData = {
readonly id: string,
readonly event_type: string,
readonly event_label: string,
readonly state: Heptapod.Enums.DeliveryGroupOutcome,
readonly count: number,
readonly first_at: string,
readonly last_at: string,
readonly first_id: number,
readonly last_id: number,
readonly recipients: string[],
readonly recipient_count: number,
readonly accepted_count: number,
readonly failed_count: number,
readonly suppressed_count: number,
readonly pending_count: number,
readonly unrecorded_count: number,
readonly last_entry_kind: string,
readonly unrecorded_reasons: Record<string, number>,
readonly logged_count: number,
};
export type IncidentNotificationPageData = {
readonly group_id: string,
readonly deliveries: Heptapod.Data.Ui.IncidentDeliveryData[],
readonly next_cursor: string | null,
readonly has_previous: boolean,
};
export type IncidentSummaryData = {
readonly count: number,
readonly total_duration_seconds: number,
readonly median_duration_seconds: number | null,
};
export type IncidentTimelineEntryData = {
readonly id: string,
readonly time: string,
readonly event: Heptapod.Data.Ui.EventData | null,
readonly note: string | null,
readonly note_author: string | null,
readonly notification_group: Heptapod.Data.Ui.IncidentNotificationGroupData | null,
};
export type IncidentsIndexData = {
readonly incidents: Heptapod.Data.Ui.IncidentData[],
readonly pagination: Heptapod.Data.Ui.PaginationData,
readonly filters: Heptapod.Data.Ui.IncidentFilterData,
readonly clients: Heptapod.Data.Ui.ClientData[],
};
export type IncidentsIndexPageData = {
readonly incidents: Heptapod.Data.Ui.IncidentData[],
readonly pagination: Heptapod.Data.Ui.PaginationData,
readonly filters: Heptapod.Data.Ui.IncidentFilterData,
readonly clients: Heptapod.Data.Ui.ClientData[],
readonly summary: Heptapod.Data.Ui.IncidentSummaryData,
};
export type InstancePageData = {
readonly instancePage: Heptapod.Data.Ui.InstanceSettingsData,
};
export type InstanceSettingsData = {
readonly name: string,
readonly mail_design: Heptapod.Contracts.Mail.MailDesign,
readonly url: string,
readonly smtp: {
host: string | null,
port: number | null,
scheme: string | null,
username_configured: boolean,
password_configured: boolean,
},
readonly mailer: string,
readonly mail_log_only: boolean,
readonly from_name: string,
readonly from_address: string,
readonly heartbeat_configured: boolean,
readonly last_external_ping: string | null,
readonly health_url: string,
readonly defaults: {
editable: boolean,
source: string,
},
readonly retention_editable: boolean,
readonly retention: {
keep_all_backups_for_days: number,
keep_daily_backups_for_days: number,
keep_weekly_backups_for_weeks: number,
keep_monthly_backups_for_months: number,
keep_yearly_backups_for_years: number,
delete_oldest_backups_when_using_more_megabytes_than: number | null,
},
};
export type InvitationData = {
readonly instance: string,
readonly inviter: string | null,
readonly email: string,
readonly role: string,
readonly client_scope: string,
readonly clients: {
id: number,
name: string,
sites_count: number,
}[],
readonly expires_at: string,
};
export type InvitationPageData = {
readonly instance: string,
readonly state: 'valid' | 'unavailable' | 'expired' | 'active-account',
readonly invitation: Heptapod.Data.Ui.InvitationData | null,
readonly token?: string,
readonly passwordRules?: string,
};
export type MaintenanceIndexPageData = {
readonly windows: Heptapod.Data.Ui.MaintenanceWindowData[],
readonly next_page: number | null,
readonly can_manage: boolean,
readonly timezone: string,
readonly sites: Heptapod.Data.Ui.MaintenanceTargetOptionData[],
readonly clients: Heptapod.Data.Ui.MaintenanceTargetOptionData[],
readonly tags: Heptapod.Data.Ui.MaintenanceTargetOptionData[],
readonly instance_timezone: string,
readonly timezones: string[],
readonly filter_clients: Heptapod.Data.Ui.MaintenanceTargetOptionData[],
readonly client_id: number | null,
readonly site_id: number | null,
readonly site_name: string | null,
readonly state: string,
readonly previous_page: number | null,
readonly total: number,
};
export type MaintenanceSiteHeaderData = {
readonly id: number | null,
readonly state: string,
readonly startsAt: string | null,
readonly endsAt: string | null,
readonly timezone: string | null,
readonly reason: string | null,
readonly muteAlerts: boolean,
};
export type MaintenanceTargetOptionData = {
readonly id: number,
readonly name: string,
readonly avatar: Heptapod.Data.Ui.ClientAvatarData | null,
readonly short_name: string | null,
readonly accent_color: string | null,
readonly is_internal: boolean,
readonly client_name: string | null,
readonly host: string | null,
readonly icon_url: string | null,
readonly icon_backing: Heptapod.Enums.SiteIconBacking,
};
export type MaintenanceWindowData = {
readonly id: number,
readonly seriesId: number | null,
readonly state: string,
readonly targetType: string,
readonly targetId: number,
readonly targetLabel: string | null,
readonly siteCount: number,
readonly name: string | null,
readonly reason: string | null,
readonly startsAt: string,
readonly endsAt: string,
readonly timezone: string,
readonly recurrenceType: string | null,
readonly interval: number,
readonly nthWeekday: number | null,
readonly dayOfWeek: number | null,
readonly muteAlerts: boolean,
readonly catchUpAtEnd: boolean,
readonly actions: string[],
readonly daysOfWeek: number[] | null,
readonly dayOfMonth: number | null,
readonly startTime: string | null,
readonly endTime: string | null,
readonly anchorDate: string | null,
readonly seriesTimezone: string | null,
};
export type MemberSettingsPageData = {
readonly roster: Heptapod.Data.Ui.MembersPageData,
};
export type MembersPageData = {
readonly members: {
id: number,
name: string,
email: string,
role: string | null,
client_ids: number[],
client_scope: string,
effective_client_scope: string,
last_active_at: string | null,
disabled: boolean,
is_last_owner: boolean,
can_edit: boolean,
can_deactivate: boolean,
can_reinvite: boolean,
allowed_roles: string[],
}[],
readonly invitations: {
id: number,
email: string,
role: string,
client_ids: number[],
client_scope: string,
inviter: string | null,
expires_at: string,
expired: boolean,
can_manage: boolean,
}[],
readonly clients: {
id: number,
name: string,
archived: boolean,
short_name: string | null,
accent_color: string | null,
avatar: Heptapod.Data.Ui.ClientAvatarData,
is_internal: boolean,
}[],
readonly invite_roles: string[],
};
export type MetricSeriesData = {
readonly key: string,
readonly unit: string,
readonly points: Heptapod.Data.Ui.ResponsePointData[],
};
export type NotificationChannelData = {
readonly id: number,
readonly channel: string,
readonly label: string,
readonly address: string | null,
readonly status: 'active' | 'paused' | 'needs_attention' | 'unverified',
readonly account_mail: boolean,
readonly locale: string | null,
readonly scope_client_id: number | null,
readonly attention_reason: string | null,
readonly last_outcome: string | null,
readonly last_outcome_diagnosis: string | null,
readonly last_outcome_at: string | null,
readonly queued_count: number,
readonly config: Record<string, any>,
readonly preferences: Record<string, Heptapod.Enums.NotificationMode>,
readonly defaults: Record<string, Heptapod.Enums.NotificationMode> | null,
readonly system_locked: boolean,
readonly system_bypasses_pause: boolean,
readonly muted_site_ids: number[],
readonly can_remove: boolean,
};
export type NotificationSettingsPageData = {
readonly recipient_kind: Heptapod.Enums.RecipientKind,
readonly recipient_id: number | null,
readonly recipient_name: string,
readonly channels: Heptapod.Data.Ui.NotificationChannelData[],
readonly grid: {
category: string,
modes: string[],
}[],
readonly schedule: {
quiet_start: string | null,
quiet_end: string | null,
quiet_weekends: boolean,
digest_time: string,
digest_weekdays: number[] | null,
timezone: string,
},
readonly sites: {
id: number,
name: string,
client_id: number,
client_name: string,
host: string,
url: string,
icon_url: string | null,
icon_backing: Heptapod.Enums.SiteIconBacking,
identity: {
label: string,
detail: string,
},
}[],
readonly clients: {
id: number,
name: string,
}[],
readonly channel_types: Heptapod.Data.Ui.ChannelTypeData[],
readonly migration_report: string[] | null,
};
export type OwnNotificationsPageData = {
readonly settings: Heptapod.Data.Ui.NotificationSettingsPageData,
};
export type PaginationData = {
readonly current_page: number,
readonly per_page: number,
readonly total: number,
readonly last_page: number,
readonly next_page_url: string | null,
readonly previous_page_url: string | null,
};
export type PerformanceNoiseData = {
readonly minimum_increase_ms: number,
readonly points: {
time: string,
baseline: number,
median: number,
threshold: number,
marked: boolean,
}[],
};
export type ProfilePageData = {
readonly mustVerifyEmail: boolean,
readonly status: string | null,
readonly timezones: string[],
};
export type ResponsePointData = {
readonly time: string,
readonly value: number | null,
readonly failure_count: number,
readonly error_count: number,
readonly warning_count: number,
readonly failure_status: Heptapod.Enums.BucketStatus | null,
};
export type ResponseTimeBucketData = {
readonly time: string,
readonly average_ms: number | null,
readonly p95_ms: number | null,
readonly maximum_ms: number | null,
readonly failure_count: number,
readonly error_count: number,
readonly warning_count: number,
};
export type ResponseTimeSeriesData = {
readonly key: Heptapod.Enums.ResponseTimeRange,
readonly points: Heptapod.Data.Ui.ResponseTimeBucketData[],
readonly recent_average_ms: number | null,
};
export type RunAllCheckData = {
readonly check_id: number,
readonly check_type: Heptapod.Contracts.CheckKey,
readonly queue: string,
readonly maximum_seconds: number,
readonly available: boolean,
};
export type RunRowData = {
readonly id: number,
readonly check_id: number,
readonly site_id: number,
readonly client_id: number,
readonly site_name: string,
readonly check_type: Heptapod.Contracts.CheckKey,
readonly queue: string,
readonly run: Heptapod.Data.Ui.CheckRunStateData,
readonly requested_at: string,
readonly finished_at: string | null,
readonly duration_ms: number | null,
readonly note: string | null,
readonly summary: string | null,
readonly summary_params: Record<string, number | string | boolean | null>,
readonly result: Heptapod.Contracts.RunResult | null,
readonly icon_url: string | null,
readonly icon_backing: Heptapod.Enums.SiteIconBacking,
readonly presentation: Heptapod.Contracts.CheckRunPresentationData | null,
};
export type RunsBoardData = {
readonly current: Heptapod.Data.Ui.CurrentRunData[],
readonly queued_more: number,
readonly finished: Heptapod.Data.Ui.FinishedRunData[],
};
export type RunsFilterData = {
readonly client: number | null,
readonly check: number | null,
readonly type: Heptapod.Contracts.CheckKey | null,
readonly state: Heptapod.Contracts.RunPhase | null,
readonly before: number | null,
readonly show_same_result: boolean,
readonly period: '24h' | '7d',
};
export type RunsHistoryPageData = {
readonly runs: Heptapod.Data.Ui.RunRowData[],
readonly next_cursor: string | null,
};
export type RunsIndexPageData = {
readonly current: Heptapod.Data.Ui.CurrentRunData[],
readonly queued_more: number,
readonly finished: Heptapod.Data.Ui.FinishedRunData[],
readonly runs: Heptapod.Data.Ui.RunRowData[],
readonly next_cursor: string | null,
readonly clients: Heptapod.Data.Ui.ClientData[],
readonly filters: Heptapod.Data.Ui.RunsFilterData,
};
export type SearchResultsData = {
readonly sites: Heptapod.Data.Ui.SearchSiteData[],
readonly clients: Heptapod.Data.Ui.ClientData[],
readonly incidents: Heptapod.Data.Ui.IncidentData[],
};
export type SearchSiteData = {
readonly id: number,
readonly name: string,
readonly url: string,
readonly client_id: number,
readonly state: Heptapod.Enums.SiteStatus,
readonly icon_url: string | null,
readonly icon_backing: Heptapod.Enums.SiteIconBacking,
readonly client_name: string | null,
};
export type SecurityPageData = {
readonly canManageTwoFactor: boolean,
readonly canManagePasskeys: boolean,
readonly passkeys: {
id: number,
name: string,
authenticator: string | null,
created_at_diff: string | null,
last_used_at_diff: string | null,
}[],
readonly passwordRules: string,
readonly twoFactorEnabled?: boolean,
readonly requiresConfirmation?: boolean,
};
export type ShellHealthData = {
readonly status: Heptapod.Enums.HealthStatus,
readonly dispatcher_at: string | null,
readonly last_result_at: string | null,
readonly check_errors: number,
readonly active_checks: number,
readonly site_count: number,
readonly open_incident_count: number,
readonly down_site_count: number,
readonly notifications_covered: boolean,
readonly system_issue_status: Heptapod.Enums.HealthStatus | null,
readonly system_issue_count: number,
readonly causes: {
title_key: string,
action_key: string,
href: string,
}[],
};
export type SiteIdentityData = {
readonly id: number,
readonly name: string,
readonly url: string,
readonly client_id: number,
readonly state: Heptapod.Enums.SiteStatus,
readonly icon_url: string | null,
readonly icon_backing: Heptapod.Enums.SiteIconBacking,
};
export type SiteListSummaryData = {
readonly total: number,
readonly attention: number,
readonly paused: number,
};
export type SiteOverviewPageData = {
readonly site: Heptapod.Data.Ui.SiteRowData,
readonly header: Heptapod.Data.Ui.SiteIdentityData,
readonly history: Heptapod.Data.Ui.UptimeBucketData[],
readonly events: Heptapod.Data.Ui.EventData[],
readonly incidents: Heptapod.Data.Ui.IncidentData[],
readonly client: Heptapod.Data.Ui.ClientData,
readonly run_all_checks: Heptapod.Data.Ui.RunAllCheckData[],
readonly can_manage: boolean,
readonly uptime_ranges: {
hours24: number | null,
days7: number | null,
days30: number | null,
days90: number | null,
},
readonly run_all_result: {
queued: number,
unavailable: number,
results: {
check_id: number,
status: string,
reason: string | null,
}[],
} | null,
readonly maintenance: Heptapod.Data.Ui.MaintenanceSiteHeaderData | null,
};
export type SiteProbeData = {
readonly final_url: string | null,
readonly status: number | null,
readonly server: string | null,
readonly certificate_issuer: string | null,
readonly certificate_expires_at: string | null,
readonly certificate_unavailable_reason: string | null,
readonly sitemap_url: string | null,
readonly failure: string | null,
readonly public_html: boolean,
readonly recommended_checks: Heptapod.Contracts.CheckKey[],
readonly normalised_url: string | null,
duplicate_message: string | null,
duplicate_site_id: number | null,
warning_site_name: string | null,
warning_site_id: number | null,
readonly failure_reason: string | null,
};
export type SiteRowData = {
readonly id: number,
readonly name: string,
readonly url: string,
readonly client_id: number,
readonly tags: string[],
readonly state: Heptapod.Enums.SiteStatus,
readonly uptime: number | null,
readonly response: Heptapod.Data.Ui.ResponsePointData[],
readonly checks: Heptapod.Data.Ui.CheckStripData[],
readonly checked_at: string | null,
readonly identity: Heptapod.Data.Ui.SiteIdentityData,
};
export type SiteSettingsPageData = {
readonly site: Heptapod.Data.Ui.SiteRowData,
readonly client: Heptapod.Data.Ui.ClientData,
readonly resume_at: string | null,
readonly check_count: number,
readonly incident_count: number,
readonly alert_sensitivity: Heptapod.Data.Ui.AlertSensitivityData,
readonly team_alert_sensitivity: Heptapod.Data.Ui.AlertSensitivityData,
readonly alert_sensitivity_presets: Heptapod.Data.Ui.AlertSensitivityData[],
readonly alert_sensitivity_bounds: {
minutes_min: number,
minutes_max: number,
stored_minutes_min: number,
stored_minutes_max: number,
recovery_min: number,
recovery_max: number,
},
readonly result_count?: number,
readonly run_all_checks: Heptapod.Data.Ui.RunAllCheckData[],
readonly maintenance: Heptapod.Data.Ui.MaintenanceSiteHeaderData | null,
readonly icon_fetched_at: string | null,
readonly icon_manual_result: Heptapod.Data.Ui.IconRefreshResultData | null,
readonly start_redirect_target_url: string | null,
readonly start_redirect_summary_key: string | null,
readonly clients: Heptapod.Data.Ui.ClientData[],
readonly timezones: string[],
readonly check_settings: Heptapod.Data.Ui.CheckSettingsData[],
};
export type SitesFilterData = {
readonly search: string | null,
readonly client_id: number | null,
readonly state: Heptapod.Enums.SiteStatus | null,
readonly tag: string | null,
readonly sort: string,
readonly check: Heptapod.Contracts.CheckKey | null,
readonly attention: boolean,
};
export type SitesIndexPageData = {
readonly sites?: Heptapod.Data.Ui.SiteRowData[],
readonly pagination: Heptapod.Data.Ui.PaginationData,
readonly filters: Heptapod.Data.Ui.SitesFilterData,
readonly clients: Heptapod.Data.Ui.ClientData[],
readonly tags: Heptapod.Data.Ui.TagData[],
readonly summary: Heptapod.Data.Ui.SiteListSummaryData,
readonly can_create: boolean,
readonly tiles: Heptapod.Data.Ui.DashboardTilesData,
readonly needs_attention: Heptapod.Data.Ui.SiteRowData[],
readonly needs_attention_total: number,
};
export type SystemHealthPageData = {
readonly health: Heptapod.SystemHealth.SystemHealthData,
readonly observed_at: string,
readonly last_result_at: string | null,
readonly check_errors: number,
};
export type SystemPageData = {
readonly systemPage: Heptapod.Data.Ui.SystemHealthPageData,
};
export type TagData = {
readonly id: number,
readonly name: string,
};
export type TeamNotificationsPageData = {
readonly settings: Heptapod.Data.Ui.NotificationSettingsPageData,
};
export type UpcomingIndexPageData = {
readonly items: Heptapod.Data.Ui.UpcomingItemData[],
readonly days: string,
readonly type: string,
readonly client_id: number | null,
readonly calendar_now: string,
readonly clients: Heptapod.Data.Ui.ClientData[],
};
export type UpcomingItemData = {
readonly key: string,
readonly date: string,
readonly type: string,
readonly site_name: string | null,
readonly client_name: string | null,
readonly detail: string,
readonly url: string,
readonly target_type: string | null,
readonly target_id: number | null,
readonly target_label: string | null,
readonly site_count: number | null,
readonly icon_url: string | null,
readonly icon_backing: Heptapod.Enums.SiteIconBacking,
readonly client_id: number | null,
};
export type UpdatesPageData = {
readonly updatesPage: Heptapod.Data.Ui.UpdatesSettingsPageData,
};
export type UpdatesSettingsPageData = {
readonly updates: {
installed_version: string,
update_status: string,
enabled: boolean,
enabled_by_server: boolean,
http_status: number | null,
checked_at: string | null,
last_success_at: string | null,
latest_version: string | null,
release_url: string | null,
published_at: string | null,
evaluated_at: string,
},
readonly backups: {
disk: string,
reachable: boolean,
archives: {
created_at: string,
size_bytes: number,
}[],
runs: {
id: number,
requested_by: number | null,
state: string,
disk: string | null,
http_status: number | null,
requested_at: string,
started_at: string | null,
completed_at: string | null,
}[],
schedule_utc: string,
retention: Record<string, number | null>,
uses_default_retention: boolean,
observed_at: string,
} | null,
};
export type UptimeBucketData = {
readonly date: string,
readonly uptime: number | null,
readonly succeeded_count: number,
readonly warning_count: number,
readonly failed_count: number,
readonly errored_count: number,
readonly status: Heptapod.Enums.BucketStatus,
readonly incident_count: number,
readonly failed_seconds: number,
};
}
}
namespace Enums {
export type AlertSensitivity = 'strict' | 'balanced' | 'relaxed' | 'patient';
export type BucketStatus = 'failed' | 'interrupted' | 'errored' | 'warning' | 'ok' | 'maintenance' | 'no_data';
export type CheckPendingReason = 'dns_accept_recheck';
export type ClientAvatarSource = 'uploaded' | 'chosen-site' | 'automatic' | 'initials' | 'generic-initials';
export type ClientScope = 'all' | 'restricted';
export type DeliveryGroupOutcome = 'accepted' | 'logged' | 'failed' | 'suppressed' | 'pending' | 'mixed' | 'unrecorded';
export type DeliveryOutcome = 'accepted' | 'rejected' | 'failed' | 'logged' | 'suppressed' | 'pending' | 'sending';
export type FreshnessSuppression = 'down' | 'maintenance' | 'snooze' | 'uptime_error';
export type HealthStatus = 'ok' | 'warn' | 'down' | 'not-configured';
export type ImportOperation = 'create' | 'update' | 'partial' | 'skipped' | 'failed';
export type ImportRunStatus = 'planned' | 'queued' | 'running' | 'completed' | 'incomplete' | 'failed';
export type IncidentCause = 'failed' | 'errored';
export type IncidentResolutionReason = 'check_disabled' | 'site_paused' | 'site_deleted';
export type NotificationCategory = 'outage' | 'failure' | 'check_error' | 'warning' | 'expiring' | 'recovery' | 'changes' | 'system' | 'report';
export type NotificationMode = 'immediate' | 'digest' | 'off';
export type RecipientKind = 'team' | 'user' | 'client';
export type ResponseTimeRange = '24h' | '7d' | '30d' | '90d';
export type SiteIconBacking = 'none' | 'light-backing-in-dark' | 'dark-backing-in-light';
export type SiteStatus = 'maintenance' | 'paused' | 'disabled' | 'unavailable' | 'down' | 'failed' | 'check-error' | 'warning' | 'pending' | 'ok';
export type SiteStatusAttention = ['down', 'failed', 'check-error', 'warning'];
export type SiteStatusSeverity = {
readonly down: 0,
readonly failed: 1,
readonly 'check-error': 2,
readonly warning: 3,
readonly pending: 4,
readonly ok: 5,
readonly maintenance: 6,
readonly paused: 7,
readonly disabled: 7,
readonly unavailable: 7,
};
}
namespace SystemHealth {
export type CheckDataMaintenanceHealth = {
readonly status: Heptapod.Enums.HealthStatus,
readonly lastSuccessAt: string | null,
readonly lastSuccessSeconds: number | null,
readonly lastFailureAt: string | null,
readonly consecutiveFailures: number,
};
export type DiskReadFailure = 'missing' | 'invalid_capacity' | 'read_failed';
export type HealthMetric = {
readonly status: Heptapod.Enums.HealthStatus,
readonly value: number | null,
readonly error: string | null,
readonly unavailableReason: 'no_sample' | 'unlimited' | null,
};
export type MailTransportHealth = {
readonly lastSuccessAt: string | null,
readonly consecutiveFailures: number,
readonly failingSince: string | null,
readonly lastFailureAt: string | null,
readonly lastFailureDiagnosis: string | null,
};
export type SystemHealthData = {
readonly queues: Record<string, Heptapod.SystemHealth.HealthMetric>,
readonly horizon: Heptapod.SystemHealth.HealthMetric,
readonly workers: {
name: string,
status: string,
workers: number,
}[],
readonly workerMemory: Heptapod.SystemHealth.HealthMetric,
readonly dispatcher: Heptapod.SystemHealth.HealthMetric,
readonly scheduler: Heptapod.SystemHealth.HealthMetric,
readonly browserServices: Record<string, Heptapod.SystemHealth.HealthMetric>,
readonly reverb: Heptapod.SystemHealth.HealthMetric,
readonly databaseBytes: number,
readonly failedJobsTotal: number,
readonly recentFailedJobs: Heptapod.SystemHealth.HealthMetric,
readonly lastExternalPing: string | null,
readonly lastLiveTerminationFailure: string | null,
readonly checkDataMaintenance: Heptapod.SystemHealth.CheckDataMaintenanceHealth,
readonly systemIssues: Heptapod.SystemHealth.SystemIssueData[],
readonly systemIssueEvaluation: {
evaluated_at: string | null,
failed_at: string | null,
error: string | null,
},
readonly mailTransport: Heptapod.SystemHealth.MailTransportHealth,
readonly deliveryPrivateHosts: string[],
};
export type SystemIssueData = {
readonly title_key: string,
readonly action_key: string,
readonly kind: Heptapod.SystemHealth.SystemIssueKind,
readonly scope: string,
readonly severity: Heptapod.Enums.HealthStatus,
readonly openedAt: string,
readonly details: Record<string, number | string | null>,
};
export type SystemIssueKind = 'check_not_fresh' | 'disk_space_low' | 'maintenance_failed' | 'backup_failed' | 'backup_stale' | 'scheduler_stale' | 'checks_not_running' | 'mail_log_only' | 'mail_transport_dead';
}
}
declare namespace Illuminate {
export type CursorPaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
path: string,
per_page: number,
next_cursor: string | null,
next_page_url: string | null,
prev_cursor: string | null,
prev_page_url: string | null,
},
};
export type CursorPaginatorInterface<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type LengthAwarePaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
total: number,
current_page: number,
first_page_url: string,
from: number | null,
last_page: number,
last_page_url: string,
next_page_url: string | null,
path: string,
per_page: number,
prev_page_url: string | null,
to: number | null,
},
};
export type LengthAwarePaginatorInterface<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
declare namespace Spatie {
namespace LaravelData {
export type CursorPaginatedDataCollection<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type PaginatedDataCollection<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
}

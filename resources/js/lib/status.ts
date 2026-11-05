/**
 * The one status vocabulary: every result, incident, event and bucket maps to a SiteState here.
 * Down is the uptime outage, Failed a non-uptime failure, Check error "we could not check".
 * Severity and the attention set come from PHP (SiteStatus::severity()/attention(), generated types).
 */
import type { CheckKey, SiteState } from '@/types/monitoring';

type CheckResult = Heptapod.Contracts.CheckResult;

/** The glossary word of each state (translation keys); the select order of the Sites status filter. */
export const statusLabels: Record<SiteState | 'running', string> = {
    running: 'Running',
    ok: 'OK',
    warning: 'Warning',
    down: 'Down',
    paused: 'Paused',
    pending: 'Pending',
    disabled: 'Off',
    unavailable: 'Unavailable',
    maintenance: 'Maintenance',
    failed: 'Failed',
    'check-error': 'Check error',
};

/** Mirrors SiteStatus::severity(); the generated type rejects any drift at compile time. */
export const siteSeverity = {
    down: 0,
    failed: 1,
    'check-error': 2,
    warning: 3,
    pending: 4,
    ok: 5,
    maintenance: 6,
    paused: 7,
    disabled: 7,
    unavailable: 7,
} as const satisfies Heptapod.Enums.SiteStatusSeverity;

/** Mirrors SiteStatus::attention(): states that need action now, worst first. */
export const attentionStates = [
    'down',
    'failed',
    'check-error',
    'warning',
] as const satisfies Heptapod.Enums.SiteStatusAttention;

export function isAttentionState(state: SiteState): boolean {
    return (attentionStates as readonly SiteState[]).includes(state);
}

/** Sorts by the PHP severity rank (worst first); equal ranks keep their relative order. */
export function bySeverity(left: SiteState, right: SiteState): number {
    return siteSeverity[left] - siteSeverity[right];
}

/** Count lists (summaries, rings, chips): severity order with OK, the residue, last. */
export const stateCountOrder = (Object.keys(siteSeverity) as SiteState[]).sort(
    (left, right) =>
        Number(left === 'ok') - Number(right === 'ok') ||
        bySeverity(left, right),
);

/** A failed result is an outage only for uptime. */
export function failureState(check: CheckKey): 'down' | 'failed' {
    return check === 'uptime' ? 'down' : 'failed';
}

/** A result of a known check: an uptime failure reads Down. */
export function runState(check: CheckKey, result: CheckResult): SiteState {
    return result === 'failed' ? failureState(check) : resultState(result);
}

/** A result without a check type (component and task results): a failure reads Failed. */
export function resultState(result: CheckResult): SiteState {
    switch (result) {
        case 'pending':
            return 'pending';
        case 'succeeded':
            return 'ok';
        case 'warning':
            return 'warning';
        case 'failed':
            return 'failed';
        case 'errored-or-timed-out':
            return 'check-error';
    }
}

/** Parses a result string from an untyped addon payload; fails loud on an unknown value. */
export function parseResult(result: string): CheckResult {
    if (
        result === 'pending' ||
        result === 'succeeded' ||
        result === 'warning' ||
        result === 'failed' ||
        result === 'errored-or-timed-out'
    )
        return result;
    throw new Error(`Unknown check result: ${result}`);
}

/** An incident's cause shape while open. */
export function incidentCauseState(incident: {
    check: CheckKey;
    cause: Heptapod.Enums.IncidentCause;
}): SiteState {
    return incident.cause === 'errored'
        ? 'check-error'
        : failureState(incident.check);
}

/** An incident row: resolved reads OK, open reads its cause. */
export function incidentState(incident: {
    check: CheckKey;
    cause: Heptapod.Enums.IncidentCause;
    resolved_at: string | null;
}): SiteState {
    return incident.resolved_at ? 'ok' : incidentCauseState(incident);
}

/** An event's severity; info events use the centre-point info ring (the maintenance shape). */
export function eventState(event: {
    severity: Heptapod.Contracts.Notifications.AlertSeverity;
    check: CheckKey | null;
}): SiteState {
    switch (event.severity) {
        case 'recovered':
            return 'ok';
        case 'warning':
            return 'warning';
        case 'error':
            return 'check-error';
        case 'info':
            return 'maintenance';
        case 'down':
            return event.check === 'uptime' ? 'down' : 'failed';
    }
}

/** The accessible label override for an event whose shape is shared with another state. */
export function eventLabel(event: {
    severity: Heptapod.Contracts.Notifications.AlertSeverity;
}): string | undefined {
    return event.severity === 'info' ? 'Information' : undefined;
}

/** Daily uptime buckets: a failed day is an uptime outage, interruptions read as Warning. */
export const bucketStates = {
    ok: 'ok',
    warning: 'warning',
    failed: 'down',
    interrupted: 'warning',
    errored: 'check-error',
    maintenance: 'maintenance',
    no_data: 'pending',
} as const satisfies Record<Heptapod.Enums.BucketStatus, SiteState>;

/** One delivery's outcome in the incident and client message histories: a logged mail never left the instance. */
export const deliveryOutcomeStates = {
    accepted: 'ok',
    rejected: 'failed',
    failed: 'failed',
    logged: 'warning',
    suppressed: 'paused',
    pending: 'pending',
    sending: 'pending',
} as const satisfies Record<Heptapod.Enums.DeliveryOutcome, SiteState>;

/** The shown state of a check: a freshness-suppressed gap reads Off. */
export function checkState(check: {
    state: SiteState;
    freshness_suppressed_by?: Heptapod.Enums.FreshnessSuppression | null;
}): SiteState {
    return check.freshness_suppressed_by ? 'disabled' : check.state;
}

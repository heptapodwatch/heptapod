import { useSyncExternalStore } from 'react';

export type RunState = {
    check_id: number;
    phase: 'queued' | 'started' | 'progress' | 'finished';
    queue_position: number | null;
    progress_n: number | null;
    progress_total: number | null;
    started_at: string | null;
    actor: string | null;
    /** Only on signals: who started the run. The shell reloads Running now for manual runs only. */
    origin?: 'scheduled' | 'manual' | 'system';
    /** The progress unit key (run.progress.<phase_label>); on progress signals and hydrated runs. */
    phase_label?: string | null;
    at?: string;
    outcome?: Extract<
        Heptapod.Contracts.RunPhase,
        'done' | 'failed' | 'cancelled' | 'skipped'
    >;
    attempt_id?: number;
    // Only on run.finished of an attempt that stored a run.
    check_run_id?: number;
    ended_at?: string;
    result?: Heptapod.Contracts.CheckResult;
};

export type LiveSignal = {
    topic: string;
    ids: Record<string, number>;
    at: string;
    run?: RunState;
};

/**
 * `ids` is empty for a coalesced state change (one signal covering many affected
 * client scopes). Such a signal only reaches channels this connection is authorized
 * for, so a filtered view must treat it as a refresh request, not as its own scope.
 */
export function isCoalescedStateChange(signal: LiveSignal): boolean {
    return Object.keys(signal.ids).length === 0;
}

type LiveSnapshot = {
    runs: ReadonlyMap<number, RunState>;
    status: 'connected' | 'connecting' | 'lost' | 'offline';
    dataFrom: string;
    reconnected: number;
    dataReloadFailed: boolean;
};

type ReverbClient = {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
    path: string;
};

export const reverbClient: ReverbClient | null = (() => {
    const meta =
        typeof document === 'undefined'
            ? null
            : document.querySelector<HTMLMetaElement>(
                  'meta[name="heptapod-reverb"]',
              );
    if (!meta) return null;
    const settings = JSON.parse(meta.content) as
        | (Omit<ReverbClient, 'host' | 'port' | 'scheme'> & {
              host: string | null;
              port: number | null;
              scheme: 'http' | 'https' | null;
          })
        | null;
    if (
        !settings ||
        typeof settings.key !== 'string' ||
        !settings.key ||
        (settings.host !== null &&
            (typeof settings.host !== 'string' || !settings.host)) ||
        (settings.port !== null &&
            (!Number.isInteger(settings.port) ||
                settings.port < 1 ||
                settings.port > 65535)) ||
        (settings.scheme !== null &&
            settings.scheme !== 'http' &&
            settings.scheme !== 'https') ||
        typeof settings.path !== 'string' ||
        (settings.path !== '' && !settings.path.startsWith('/'))
    ) {
        throw new Error('Invalid public Reverb configuration.');
    }
    const scheme = settings.scheme ?? window.location.protocol.slice(0, -1);
    if (scheme !== 'http' && scheme !== 'https')
        throw new Error('Invalid Reverb page origin.');
    return {
        key: settings.key,
        host: settings.host ?? window.location.hostname,
        port:
            settings.port ??
            (window.location.port
                ? Number(window.location.port)
                : scheme === 'https'
                  ? 443
                  : 80),
        scheme,
        path: settings.path,
    };
})();

export const liveConfigured = reverbClient !== null;
let snapshot: LiveSnapshot = {
    runs: new Map(),
    status: liveConfigured ? 'connecting' : 'offline',
    dataFrom: new Date().toISOString(),
    reconnected: 0,
    dataReloadFailed: false,
};
let connectedBefore = false;
const listeners = new Set<() => void>();
const stateListeners = new Set<(signal: LiveSignal) => void>();

function update(changes: Partial<LiveSnapshot>) {
    snapshot = { ...snapshot, ...changes };
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

/** One field of the stream state: a component re-renders only when that field changes. */
export function useStreamState<Key extends keyof LiveSnapshot>(
    key: Key,
): LiveSnapshot[Key] {
    return useSyncExternalStore(
        subscribe,
        () => snapshot[key],
        () => snapshot[key],
    );
}

export function useRunState(checkId: number) {
    return useSyncExternalStore(
        subscribe,
        () => snapshot.runs.get(checkId),
        () => undefined,
    );
}

/** A newly hydrated attempt wins over a retained signal from an older run. */
export function currentRunState(
    live: RunState | undefined,
    persisted: Heptapod.Data.Ui.CheckRunStateData | null | undefined,
): RunState | undefined {
    if (
        live &&
        (!persisted?.requested_at ||
            (live.at !== undefined &&
                new Date(live.at).getTime() >=
                    new Date(persisted.requested_at).getTime()))
    ) {
        return live;
    }
    if (!persisted || !['queued', 'running'].includes(persisted.phase)) {
        return undefined;
    }
    return {
        check_id: persisted.check_id,
        phase: persisted.phase === 'queued' ? 'queued' : 'started',
        queue_position: persisted.queue_position,
        progress_n: persisted.progress_n,
        progress_total: persisted.progress_total,
        started_at: persisted.started_at,
        actor: persisted.actor,
        phase_label: persisted.phase_label,
        origin: persisted.origin ?? undefined,
        attempt_id: persisted.attempt_id ?? undefined,
        at: persisted.requested_at ?? undefined,
    };
}

export function onLiveSignal(listener: (signal: LiveSignal) => void) {
    stateListeners.add(listener);
    return () => stateListeners.delete(listener);
}

export function receiveLiveSignal(signal: LiveSignal) {
    if (signal.topic.startsWith('run.') && signal.run) {
        const runs = new Map(snapshot.runs);
        runs.set(signal.run.check_id, { ...signal.run, at: signal.at });
        update({ runs });
        stateListeners.forEach((listener) => listener(signal));
    } else if (
        signal.topic === 'state.changed' ||
        signal.topic === 'import.progress' ||
        signal.topic === 'import.changed' ||
        signal.topic === 'backup.changed'
    ) {
        stateListeners.forEach((listener) => listener(signal));
    }
}

/**
 * Returns true for the tab's first connection. That one is no reconnect: the page was just rendered, so LiveProvider
 * asks the server whether anything changed since then instead of reloading every page prop.
 */
export function setStreamStatus(status: LiveSnapshot['status']): boolean {
    if (snapshot.status === status) return false;
    const connected = status === 'connected';
    const first = connected && !connectedBefore;
    if (connected) connectedBefore = true;
    update({
        status,
        reconnected:
            connected && !first
                ? snapshot.reconnected + 1
                : snapshot.reconnected,
        runs: connected ? snapshot.runs : new Map(),
    });
    return first;
}

/** Live views reload as after a reconnect: a change happened before the first subscription was ready. */
export function requestCatchUp() {
    update({ reconnected: snapshot.reconnected + 1 });
}

export function markLiveDataFresh() {
    update({ dataFrom: new Date().toISOString(), dataReloadFailed: false });
}

export function markLiveDataStale() {
    update({ dataReloadFailed: true });
}

import type { ReloadOptions } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import {
    markLiveDataStale,
    onLiveSignal,
    useStreamState,
    type LiveSignal,
} from '@/lib/live-store';

export {
    isCoalescedStateChange,
    useRunState,
    useStreamState,
} from '@/lib/live-store';

export function useLiveReload(
    topics: string[],
    only: string[],
    filter?: (signal: LiveSignal) => boolean,
    enabled = true,
    {
        coalesceMs = 1_000,
        pollFallback = true,
        missingResource,
    }: {
        coalesceMs?: number;
        pollFallback?: boolean;
        missingResource?: { url: string; message: string };
    } = {},
): (options?: ReloadOptions) => void {
    const { url } = usePage();
    const { status, reconnected, dataReloadFailed } = useStreamState();
    const latest = useRef({ topics, only, filter, missingResource });
    latest.current = { topics, only, filter, missingResource };
    const suspended = useRef(false);
    const missingHandled = useRef(false);
    const cancelReloads = useRef(new Set<() => void>());
    const schedule = useRef<() => void>(() => {});
    const lastReconnect = useRef(reconnected);
    const isCurrentPage = () => {
        const pageUrl = new URL(url, window.location.origin);
        return (
            window.location.pathname === pageUrl.pathname &&
            window.location.search === pageUrl.search
        );
    };

    // Manual partial reloads use the same resource-gone boundary as live reloads.
    const reloadPage = (options: ReloadOptions = {}) => {
        let cancel: (() => void) | undefined;
        router.reload({
            ...options,
            onCancelToken: (token) => {
                cancel = token.cancel;
                cancelReloads.current.add(cancel);
                options.onCancelToken?.(token);
            },
            onHttpException: (response) => {
                const parent = latest.current.missingResource;
                if (response.status !== 404 || !parent) {
                    return options.onHttpException?.(response);
                }
                if (
                    !missingHandled.current &&
                    !suspended.current &&
                    isCurrentPage()
                ) {
                    missingHandled.current = true;
                    router.visit(parent.url, {
                        replace: true,
                        onSuccess: () =>
                            router.flash('toast', {
                                type: 'success',
                                message: parent.message,
                            }),
                    });
                }
                return false;
            },
            onFinish: (visit) => {
                if (cancel) cancelReloads.current.delete(cancel);
                options.onFinish?.(visit);
            },
        });
    };

    useEffect(() => {
        suspended.current = false;
        missingHandled.current = false;
        let timer: number | undefined;
        let mounted = true;
        let inFlight = false;
        let dirty = false;
        const reload = () => {
            timer = undefined;
            if (!mounted || !isCurrentPage() || missingHandled.current) return;
            if (document.hidden || suspended.current || inFlight) {
                dirty = true;
                return;
            }
            dirty = false;
            inFlight = true;
            reloadPage({
                only: latest.current.only,
                onNetworkError: () => {
                    markLiveDataStale();
                    return false;
                },
                onFinish: () => {
                    inFlight = false;
                    if (mounted && dirty) schedule.current();
                },
            });
        };
        schedule.current = () => {
            if (!enabled || !mounted || missingHandled.current) return;
            if (document.hidden || suspended.current || inFlight) {
                dirty = true;
                return;
            }
            if (timer === undefined)
                timer = window.setTimeout(reload, coalesceMs);
        };
        const unsubscribe = enabled
            ? onLiveSignal((signal) => {
                  if (
                      latest.current.topics.includes(signal.topic) &&
                      (!latest.current.filter || latest.current.filter(signal))
                  ) {
                      schedule.current();
                  }
              })
            : () => {};
        // A foreground visit owns navigation. Its redirect must win over stale reloads.
        const removeStart = router.on('start', ({ detail }) => {
            if (detail.visit.async) return;
            suspended.current = true;
            if (timer !== undefined) dirty = true;
            window.clearTimeout(timer);
            timer = undefined;
            cancelReloads.current.forEach((cancel) => cancel());
        });
        const removeFinish = router.on('finish', ({ detail }) => {
            if (detail.visit.async || !mounted || !isCurrentPage()) return;
            suspended.current = false;
            if (dirty) schedule.current();
        });
        const onVisible = () => {
            if (!document.hidden) schedule.current();
        };
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            mounted = false;
            suspended.current = true;
            unsubscribe();
            window.clearTimeout(timer);
            cancelReloads.current.forEach((cancel) => cancel());
            removeStart();
            removeFinish();
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [enabled, coalesceMs, url]);

    useEffect(() => {
        if (!enabled) return;
        if (reconnected > lastReconnect.current) {
            schedule.current();
        }
        lastReconnect.current = reconnected;
        if (!pollFallback) return;
        if (status !== 'lost' && status !== 'offline' && !dataReloadFailed)
            return;
        const poll = window.setInterval(() => {
            if (!document.hidden) schedule.current();
        }, 15_000);
        return () => window.clearInterval(poll);
    }, [enabled, status, reconnected, dataReloadFailed, pollFallback]);

    return reloadPage;
}

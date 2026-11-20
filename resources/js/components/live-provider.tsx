import { echo, useConnectionStatus } from '@laravel/echo-react';
import { router, usePage } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import {
    liveConfigured,
    markLiveDataFresh,
    receiveLiveSignal,
    requestCatchUp,
    setStreamStatus,
    type LiveSignal,
} from '@/lib/live-store';
import { changes } from '@/routes/live';

/** Catches up only when the server saw a change between this page's render and the first subscription. */
async function catchUpSinceRender() {
    const since = document.querySelector<HTMLMetaElement>(
        'meta[name="heptapod-rendered-at"]',
    )?.content;
    if (!since) throw new Error('The page names no render time.');
    // A failed check still reports its error, but the views reload as if a change was seen.
    let changed = true;
    try {
        const response = await fetch(changes.url({ query: { since } }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            throw new Error(`Live change check failed: ${response.status}`);
        }
        ({ changed } = (await response.json()) as { changed: boolean });
    } finally {
        if (changed) {
            router.flushAll();
            requestCatchUp();
        }
    }
}

export function LiveProvider({ children }: { children: ReactNode }) {
    const { liveChannels } = usePage().props;
    const connected = useConnectionStatus() === 'connected';
    const channels = liveChannels.join(',');

    useEffect(() => router.on('success', markLiveDataFresh), []);

    useEffect(() => {
        if (!liveConfigured) {
            setStreamStatus('offline');
            return;
        }
        const names = channels.split(',').filter(Boolean);
        if (names.length === 0) {
            setStreamStatus('offline');
            return;
        }
        setStreamStatus('connecting');
        const lost = window.setTimeout(() => setStreamStatus('lost'), 10_000);
        if (!connected) return () => window.clearTimeout(lost);

        const pending = new Set(names);
        let active = true;
        names.forEach((name) =>
            echo()
                .join(name)
                .listen('.LiveSignal', (signal: LiveSignal) => {
                    // A prefetched page may predate the change; the next visit reads it fresh. Progress changes no page.
                    if (
                        signal.topic !== 'run.progress' &&
                        signal.topic !== 'import.progress'
                    ) {
                        router.flushAll();
                    }
                    receiveLiveSignal(signal);
                })
                .subscribed(() => {
                    if (!active) return;
                    pending.delete(name);
                    if (pending.size === 0) {
                        window.clearTimeout(lost);
                        if (setStreamStatus('connected'))
                            void catchUpSinceRender();
                        // A reconnect may have missed changes; the views reload, the prefetched pages go.
                        else router.flushAll();
                    }
                })
                .error(() => {
                    if (active) setStreamStatus('lost');
                }),
        );
        return () => {
            active = false;
            window.clearTimeout(lost);
            names.forEach((name) => echo().leaveChannel(`presence-${name}`));
        };
    }, [channels, connected]);

    return children;
}

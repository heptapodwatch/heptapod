import { echo, useConnectionStatus } from '@laravel/echo-react';
import { router, usePage } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import {
    liveConfigured,
    markLiveDataFresh,
    receiveLiveSignal,
    setStreamStatus,
    type LiveSignal,
} from '@/lib/live-store';

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
                .listen(
                    '.LiveSignal',
                    receiveLiveSignal as (event: LiveSignal) => void,
                )
                .subscribed(() => {
                    if (!active) return;
                    pending.delete(name);
                    if (pending.size === 0) {
                        window.clearTimeout(lost);
                        setStreamStatus('connected');
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

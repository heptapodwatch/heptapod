import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { probe as sitesProbe } from '@/routes/sites';

export function useSiteUrlProbe(url: string, auto: boolean, siteId?: number) {
    const { t } = useTranslation();
    const [probe, setProbe] = useState<Heptapod.Data.Ui.SiteProbeData | null>(
        null,
    );
    const [probeState, setProbeState] = useState<
        'idle' | 'loading' | 'done' | 'error'
    >('idle');
    const [probeError, setProbeError] = useState<string | null>(null);
    const abort = useRef<AbortController | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const clearProbe = useCallback(() => {
        clearTimeout(timer.current);
        abort.current?.abort();
        setProbe(null);
        setProbeError(null);
        setProbeState('idle');
    }, []);
    const runProbe = useCallback(
        async (force = false) => {
            clearTimeout(timer.current);
            if (probe && !force) return probe;
            abort.current?.abort();
            if (!url.trim()) {
                clearProbe();
                return null;
            }
            const controller = new AbortController();
            abort.current = controller;
            setProbe(null);
            setProbeError(null);
            setProbeState('loading');
            try {
                const token = document.cookie
                    .split('; ')
                    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1];
                if (!token)
                    throw new Error('Missing CSRF cookie for site probe');
                const response = await fetch(sitesProbe.url(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(token),
                    },
                    body: JSON.stringify({
                        url,
                        ...(siteId ? { site_id: siteId } : {}),
                    }),
                });
                if (!response.ok) {
                    const error =
                        response.status === 422 ? await response.json() : null;
                    if (!controller.signal.aborted) {
                        setProbeError(
                            error?.errors?.url?.[0] ??
                                t('sites.url_probe_failed'),
                        );
                        setProbeState('error');
                    }
                    return null;
                }
                const result: Heptapod.Data.Ui.SiteProbeData =
                    await response.json();
                if (controller.signal.aborted) return null;
                setProbe(result);
                setProbeState(result.failure ? 'error' : 'done');
                return result;
            } catch {
                if (!controller.signal.aborted) {
                    setProbeError(t('sites.url_probe_failed'));
                    setProbeState('error');
                }
                return null;
            }
        },
        [url, siteId, clearProbe, t, probe],
    );
    useEffect(() => {
        if (!auto || !url.trim() || probe?.normalised_url === url) return;
        timer.current = setTimeout(() => void runProbe(), 600);
        return () => clearTimeout(timer.current);
    }, [url, auto, runProbe]);
    useEffect(() => () => abort.current?.abort(), [url]);

    return { probe, probeState, probeError, runProbe, clearProbe };
}

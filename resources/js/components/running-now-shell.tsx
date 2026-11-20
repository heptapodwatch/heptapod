import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { RunGlyph, runOutcomeWord } from '@/components/run-family';
import { useLiveReload } from '@/hooks/use-live';
import { shellRuns } from '@/hooks/use-running-now';
import { checkTypes } from '@/lib/check-types';
import type { RunState } from '@/lib/live-store';

const toastedKey = 'heptapod.running-now.toasted';
const noLiveRuns = new Map<number, RunState>();

/**
 * Keeps the shared Running now data current on every page and toasts the viewer's own results. Only lifecycle changes
 * reload it (a manual run queued or finished, an import queued, started or finished, a backup changed); check progress
 * comes from the live run map, so scheduled runs and progress ticks never reload.
 * The toast follows server state, not the tab that clicked Run check, so it survives a reload; each tab shows it once per
 * attempt (an import's "Run again" is a new attempt).
 */
export function RunningNowShell() {
    const { t } = useTranslation();
    useLiveReload(
        ['run.queued', 'run.finished', 'import.changed', 'backup.changed'],
        ['runningNow'],
        (signal) => !signal.run || signal.run.origin === 'manual',
    );
    const data = usePage().props.runningNow;
    const [visible, setVisible] = useState(
        () => document.visibilityState === 'visible',
    );
    useEffect(() => {
        const onChange = () =>
            setVisible(document.visibilityState === 'visible');
        document.addEventListener('visibilitychange', onChange);
        return () => document.removeEventListener('visibilitychange', onChange);
    }, []);

    useEffect(() => {
        if (!visible) return;
        const toasted = new Set(
            JSON.parse(sessionStorage.getItem(toastedKey) ?? '[]') as string[],
        );
        // Server results only: a tab opened later than a minute after the run finished never sees it here.
        const due = shellRuns(data, noLiveRuns).filter(
            (run) =>
                run.mine &&
                run.lifecycle === 'finished' &&
                !toasted.has(run.key),
        );
        if (due.length === 0) return;
        due.forEach((run) => {
            toasted.add(run.key);
            toast(
                run.check
                    ? `${t(checkTypes[run.check.check_type].label)} · ${run.check.site_name}`
                    : run.kind === 'import'
                      ? t('run.import')
                      : t('run.backup'),
                {
                    id: `own-run-${run.key}`,
                    icon: <RunGlyph phase="finished" outcome={run.outcome} />,
                    description: t(runOutcomeWord[run.outcome!]),
                    action: {
                        label:
                            run.kind === 'check'
                                ? t('Open check')
                                : run.kind === 'import'
                                  ? t('run.openImport')
                                  : t('run.openBackups'),
                        onClick: () => router.visit(run.href),
                    },
                },
            );
        });
        sessionStorage.setItem(toastedKey, JSON.stringify([...toasted]));
    }, [data, visible]);
    return null;
}

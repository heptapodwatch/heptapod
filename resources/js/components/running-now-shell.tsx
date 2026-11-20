import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { RunGlyph, runOutcome, runOutcomeWord } from '@/components/run-family';
import { useLiveReload } from '@/hooks/use-live';
import { checkTypes } from '@/lib/check-types';
import { show as checkShow } from '@/routes/sites/checks';

const toastedKey = 'heptapod.running-now.toasted';

/**
 * Keeps the shared Running now data current on every page and toasts the viewer's own results. Only the start and end of
 * a manual run reload it (progress comes from the live run map), so scheduled runs and progress ticks never reload.
 * The toast follows server state, not the tab that clicked Run check, so it survives a reload; each tab shows it once.
 */
export function RunningNowShell() {
    const { t } = useTranslation();
    useLiveReload(
        ['run.queued', 'run.finished'],
        ['runningNow'],
        (signal) => signal.run?.origin === 'manual',
    );
    const runs = usePage().props.runningNow?.check_runs;
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
            JSON.parse(sessionStorage.getItem(toastedKey) ?? '[]') as number[],
        );
        // A tab opened later than a minute after the run finished never sees it here: the data holds a minute.
        const due = (runs ?? []).filter(
            (run) =>
                run.mine &&
                run.result !== null &&
                run.run.attempt_id !== null &&
                !toasted.has(run.run.attempt_id),
        );
        if (due.length === 0) return;
        due.forEach((run) => {
            const attemptId = run.run.attempt_id!;
            const outcome = runOutcome(run.result!);
            toasted.add(attemptId);
            toast(`${t(checkTypes[run.check_type].label)} · ${run.site_name}`, {
                id: `own-run-${attemptId}`,
                icon: <RunGlyph phase="finished" outcome={outcome} />,
                description: t(runOutcomeWord[outcome]),
                action: {
                    label: t('Open check'),
                    onClick: () =>
                        router.visit(
                            checkShow.url({
                                site: run.site_id,
                                check: run.check_id,
                            }),
                        ),
                },
            });
        });
        sessionStorage.setItem(toastedKey, JSON.stringify([...toasted]));
    }, [runs, visible]);
    return null;
}

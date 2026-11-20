import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { RunGlyph, runOutcome, runOutcomeWord } from '@/components/run-family';
import { useLiveReload } from '@/hooks/use-live';
import { backupOutcome, importOutcome } from '@/hooks/use-running-now';
import { checkTypes } from '@/lib/check-types';
import { show as importRun } from '@/routes/settings/import/ohdear/runs';
import { edit as updatesPage } from '@/routes/settings/updates';
import { show as checkShow } from '@/routes/sites/checks';

const toastedKey = 'heptapod.running-now.toasted';

/**
 * Keeps the shared Running now data current on every page and toasts the viewer's own results. Only the start and end of
 * a manual run, import progress and backup changes reload it (check progress comes from the live run map), so scheduled
 * runs and progress ticks never reload.
 * The toast follows server state, not the tab that clicked Run check, so it survives a reload; each tab shows it once.
 */
export function RunningNowShell() {
    const { t } = useTranslation();
    useLiveReload(
        ['run.queued', 'run.finished', 'import.progress', 'backup.changed'],
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
        if (!visible || !data) return;
        const toasted = new Set(
            JSON.parse(sessionStorage.getItem(toastedKey) ?? '[]') as string[],
        );
        // A tab opened later than a minute after the run finished never sees it here: the data holds a minute.
        const due = [
            ...data.check_runs.flatMap((run) =>
                run.mine && run.result !== null && run.run.attempt_id !== null
                    ? [
                          {
                              key: `check:${run.run.attempt_id}`,
                              title: `${t(checkTypes[run.check_type].label)} · ${run.site_name}`,
                              outcome: runOutcome(run.result),
                              href: checkShow.url({
                                  site: run.site_id,
                                  check: run.check_id,
                              }),
                              action: t('Open check'),
                          },
                      ]
                    : [],
            ),
            ...data.imports.flatMap((run) =>
                run.mine &&
                (run.status === 'completed' ||
                    run.status === 'incomplete' ||
                    run.status === 'failed')
                    ? [
                          {
                              key: `import:${run.id}`,
                              title: t('run.import'),
                              outcome: importOutcome[run.status],
                              href: importRun.url(run.id),
                              action: t('run.openImport'),
                          },
                      ]
                    : [],
            ),
            ...(data.backup?.mine &&
            (data.backup.state === 'complete' || data.backup.state === 'failed')
                ? [
                      {
                          key: `backup:${data.backup.id}`,
                          title: t('run.backup'),
                          outcome: backupOutcome[data.backup.state],
                          href: updatesPage.url(),
                          action: t('run.openBackups'),
                      },
                  ]
                : []),
        ].filter((item) => !toasted.has(item.key));
        if (due.length === 0) return;
        due.forEach((item) => {
            toasted.add(item.key);
            toast(item.title, {
                id: `own-run-${item.key}`,
                icon: <RunGlyph phase="finished" outcome={item.outcome} />,
                description: t(runOutcomeWord[item.outcome]),
                action: {
                    label: item.action,
                    onClick: () => router.visit(item.href),
                },
            });
        });
        sessionStorage.setItem(toastedKey, JSON.stringify([...toasted]));
    }, [data, visible]);
    return null;
}

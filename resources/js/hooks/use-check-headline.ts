import { useTranslation } from 'react-i18next';
import { useFormat } from '@/hooks/use-format';

/** One current-check headline for overview cards and check tabs. */
export function useCheckHeadline() {
    const { t } = useTranslation();
    const format = useFormat();
    return (check: Heptapod.Data.Ui.CheckStripData): string => {
        if (!check.enabled) return t('This check is turned off.');
        if (check.unavailable_reason) return t(check.unavailable_reason);
        if (check.pending_recheck) return t('Checking the accepted records…');
        if (check.freshness_suppressed_by) {
            if (!check.freshness_suppression_label_key)
                throw new Error('A suppressed gap needs its reason label.');
            return `${t('No current data')} · ${t(check.freshness_suppression_label_key)}`;
        }
        let headline = check.presentation
            ? format.presentation(check.presentation)
            : check.summary
              ? format.summary(check.summary, check.summary_params)
              : t('Awaiting first check result');
        if (
            check.key === 'lighthouse' &&
            check.result === 'errored-or-timed-out' &&
            check.presentation?.explanation
        )
            headline = t('Not checked: :reason', { reason: headline });
        if (check.held_after_recovery) {
            if (check.next_run_at === null)
                throw new Error('A held check needs its next run.');
            headline = t('Re-checking after recovery · next run :time', {
                time: format.timestamp(check.next_run_at),
            });
        }
        if (
            (check.key === 'broken_links' || check.key === 'mixed_content') &&
            check.open_incident_requires_complete_recovery &&
            (check.result === 'errored-or-timed-out' ||
                check.summary?.startsWith('crawl.not_crawlable.'))
        )
            headline += ` ${t('crawl.previous_findings_unverified')}`;
        return headline;
    };
}

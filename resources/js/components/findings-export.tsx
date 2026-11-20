import { useTranslation } from 'react-i18next';
import { ExportButton, type ExportScope } from '@/components/export-sheet';

/** Where a findings card's export lives: the check tab, or the incident page with its episode. */
export type FindingsExportContext = {
    url: (
        format: Heptapod.Enums.ExportFormat,
        query: { run: number; observed_at: string; shown?: number },
    ) => string;
};

/**
 * The findings card's Export (heptapod-ui-rules Exports) for one retained snapshot. The card lists the first rows of
 * each group (findings, not verifiable); `shownPerGroup` is that bound, so the server returns exactly those rows.
 */
export function FindingsExport({
    context,
    run,
    total,
    shown,
    shownPerGroup,
}: {
    context: FindingsExportContext;
    run: { id: number; endedAt: string };
    total: number;
    shown: number;
    shownPerGroup: number;
}) {
    const { t } = useTranslation();
    const query = { run: run.id, observed_at: run.endedAt };
    const scopes: ExportScope[] = [
        {
            key: 'all',
            label: t('export.scope.all_findings', { count: total }),
            count: total,
            url: (format) => context.url(format, query),
        },
        ...(shown < total
            ? [
                  {
                      key: 'shown',
                      label: t('export.scope.shown', { count: shown }),
                      count: shown,
                      url: (format: Heptapod.Enums.ExportFormat) =>
                          context.url(format, {
                              ...query,
                              shown: shownPerGroup,
                          }),
                  },
              ]
            : []),
    ];
    return <ExportButton kind="findings" scopes={scopes} />;
}

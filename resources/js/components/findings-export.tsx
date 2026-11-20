import { useTranslation } from 'react-i18next';
import { ExportButton, type ExportScope } from '@/components/export-sheet';

/** Where a findings card's export lives: the check tab, or the incident page with its episode. */
export type FindingsExportContext = {
    url: (
        format: Heptapod.Enums.ExportFormat,
        query: Record<string, string | number>,
    ) => string;
};

type Page = Heptapod.Contracts.PaginationData | null;

/**
 * The findings card's Export (heptapod-ui-rules Exports) for one retained snapshot: All, and This page once a group has
 * more than one page. This page sends each group's own page parameters, so the server returns exactly the listed rows.
 */
export function FindingsExport({
    context,
    run,
    findings,
    notVerifiable,
}: {
    context: FindingsExportContext;
    run: { id: number; endedAt: string };
    /** The findings group's page; null when the group is not paged. */
    findings: Page;
    notVerifiable: Page;
}) {
    const { t } = useTranslation();
    const query = { run: run.id, observed_at: run.endedAt };
    const groups = [findings, notVerifiable].filter(
        (page): page is Heptapod.Contracts.PaginationData => page !== null,
    );
    const total = groups.reduce((sum, page) => sum + page.total, 0);
    const shown = groups.reduce(
        (sum, page) =>
            sum +
            Math.max(
                0,
                Math.min(
                    page.per_page,
                    page.total - (page.current_page - 1) * page.per_page,
                ),
            ),
        0,
    );
    const pageQuery = Object.fromEntries(
        groups.flatMap((page) => [
            [page.page_name, page.current_page],
            [page.page_name.replace(/page$/, 'per_page'), page.per_page],
        ]),
    );
    const scopes: ExportScope[] = [
        {
            key: 'all',
            label: t('export.scope.all_findings', { count: total }),
            count: total,
            url: (format) => context.url(format, query),
        },
        ...(groups.some((page) => page.last_page > 1)
            ? [
                  {
                      key: 'page',
                      label: t('export.scope.page', { count: shown }),
                      count: shown,
                      url: (format: Heptapod.Enums.ExportFormat) =>
                          context.url(format, { ...query, ...pageQuery }),
                  },
              ]
            : []),
    ];
    return <ExportButton kind="findings" scopes={scopes} />;
}

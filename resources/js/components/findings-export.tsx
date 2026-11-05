import { useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
    PageHeaderAction,
    useInActionsMenu,
} from '@/components/page-header-actions';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import type { FindingsExportGroup } from '@/lib/findings-export';

export type FindingsExportContext = {
    site: string;
    check: string;
    headline: string;
    checkedAt: string | null;
    checkedRunId: number | null;
    incidentId?: number;
    downloadUrl: (runId: number, observedAt: string) => string;
};

/** Export the same retained rows as the table, without shortened display URLs. */
export function FindingsExport({
    context,
    observedAt,
    observedRunId,
    groups,
    downloadUrl,
    totalRows,
    totalNotVerifiable = 0,
    onClipboardUnavailable,
}: {
    context: FindingsExportContext;
    observedAt: string | null;
    observedRunId: number;
    groups: FindingsExportGroup[];
    downloadUrl: string;
    totalRows: number;
    totalNotVerifiable?: number;
    /** The menu item unmounts when the menu closes, so the fallback dialog's text lives with the card (FindingsExportFallback). */
    onClipboardUnavailable: (text: string) => void;
}) {
    const { t } = useTranslation();
    const inMenu = useInActionsMenu();
    const headers = [
        t('URL'),
        t('Result'),
        t('Found on'),
        t('Link text'),
        t('Since'),
        t('New'),
    ];
    const rows = groups.flatMap((group) =>
        group.rows.map((row) => [
            row.url,
            group.resultLabel ?? row.result,
            row.foundOn ?? '',
            row.linkText ?? '',
            row.since ?? t('Not retained'),
            row.isNew ? t('Yes') : t('No'),
        ]),
    );
    const shownNotVerifiable = groups
        .filter((group) => group.notVerifiable)
        .reduce((count, group) => count + group.rows.length, 0);
    const shownFindings = rows.length - shownNotVerifiable;
    const totalFindings = totalRows - totalNotVerifiable;
    const notVerifiableSuffix =
        totalNotVerifiable > shownNotVerifiable
            ? t('(+{{shown}} of {{total}} not verifiable)', {
                  shown: shownNotVerifiable,
                  total: totalNotVerifiable,
              })
            : t('(+{{count}} not verifiable)', { count: shownNotVerifiable });
    const findingsCount =
        totalFindings > shownFindings
            ? t(
                  'Findings: {{shown}} of {{total}} shown — download the CSV for all',
                  { shown: shownFindings, total: totalFindings },
              )
            : t('Findings: {{count}}', { count: shownFindings });
    const [, copy] = useClipboard();
    const markdownCell = (value: string) =>
        value
            .replaceAll('\\', '\\\\')
            .replaceAll('|', '\\|')
            .replace(/\r\n|\r|\n/g, '<br>');
    const markdownRow = (cells: string[]) =>
        `| ${cells.map(markdownCell).join(' | ')} |`;
    const text = [
        `${t('Site')}: ${context.site}`,
        `${t('Check')}: ${context.check}`,
        `${t('Status')}: ${context.headline}`,
        ...(context.incidentId
            ? [`${t('Incident')} #${context.incidentId}`]
            : []),
        totalNotVerifiable > 0
            ? `${findingsCount} ${notVerifiableSuffix}`
            : findingsCount,
        ...(context.checkedAt
            ? [`${t('Checked at')}: ${context.checkedAt}`]
            : []),
        ...(observedAt &&
        (observedRunId !== context.checkedRunId ||
            observedAt !== context.checkedAt)
            ? [`${t('Findings from')}: ${observedAt}`]
            : []),
        '',
        markdownRow(headers),
        markdownRow(headers.map(() => '---')),
        ...rows.map(markdownRow),
    ].join('\n');

    const copyFindings = async () => {
        if (await copy(text)) {
            const copied =
                totalFindings > shownFindings
                    ? t('Copied {{shown}} of {{total}} findings', {
                          shown: shownFindings,
                          total: totalFindings,
                      })
                    : t('Copied {{count}} findings', { count: shownFindings });
            toast.success(
                totalNotVerifiable > 0
                    ? `${copied} ${notVerifiableSuffix}`
                    : copied,
            );
        } else {
            onClipboardUnavailable(text);
        }
    };
    // Rendered inside a CardToolbarActions: buttons at sm and up, items of its More actions menu below.
    return (
        <>
            <PageHeaderAction
                type="button"
                variant="outline"
                size="sm"
                onClick={() => void copyFindings()}
                disabled={rows.length === 0}
            >
                {t('Copy')}
            </PageHeaderAction>
            <PageHeaderAction variant="outline" size="sm" asChild>
                <a href={downloadUrl} download>
                    {t('Download CSV')}
                </a>
            </PageHeaderAction>
            {totalRows > rows.length && !inMenu && (
                <span className="w-full text-right text-xs text-muted-foreground">
                    {t('Copy: {{shown}} shown · CSV: all {{total}}', {
                        shown: rows.length,
                        total: totalRows,
                    })}
                </span>
            )}
        </>
    );
}

/** The clipboard fallback for FindingsExport; stays mounted outside the actions menu so closing the menu keeps it open. */
export function FindingsExportFallback({
    manualText,
    onClose,
}: {
    manualText: string | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const textarea = useRef<HTMLTextAreaElement>(null);
    return (
        <>
            <Dialog
                open={manualText !== null}
                onOpenChange={(open) => {
                    if (!open) onClose();
                }}
            >
                <DialogContent
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();
                        textarea.current?.focus();
                        textarea.current?.select();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{t('Copy findings')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Clipboard unavailable. Copy the selected text manually.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <Textarea
                        ref={textarea}
                        readOnly
                        value={manualText ?? ''}
                        aria-label={t('Findings export')}
                        className="field-sizing-fixed h-72 font-mono text-xs"
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}

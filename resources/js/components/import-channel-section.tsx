import { Bell } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '@/components/empty-state';
import { ImportOperationBadge } from '@/components/import-operation-badge';
import { ListFooter } from '@/components/list-footer';
import { SectionHeading } from '@/components/section-heading';
import { ListRow } from '@/components/ui/table';
import { checkTypes } from '@/lib/check-types';

type Row = Heptapod.Data.Import.ImportChannelRowData;

export function ImportChannelSection({
    rows,
    plannedClient,
}: {
    rows: Row[];
    plannedClient?: (tags: string[]) => string | null;
}) {
    const { t } = useTranslation();

    return (
        <section aria-labelledby="import-channels" className="space-y-3 py-6">
            <SectionHeading id="import-channels">
                {t('import.section.channels')}
            </SectionHeading>
            {rows.length === 0 ? (
                <EmptyState
                    icon={Bell}
                    title={t('import.channels.empty')}
                    description={t('import.channels.empty_description')}
                />
            ) : (
                <>
                    <div className="@container/table overflow-hidden rounded-[10px] border">
                        {rows.map((row) => {
                            const client = plannedClient
                                ? plannedClient(row.tags)
                                : row.client_name;
                            const unassigned =
                                !!plannedClient &&
                                row.tags.length > 0 &&
                                client === null &&
                                row.operation !== 'skipped';
                            const operation = unassigned
                                ? 'skipped'
                                : row.operation;
                            const notices = unassigned
                                ? ['import.notice.channel_no_client']
                                : row.notices;
                            return (
                                <ListRow
                                    key={row.source_id}
                                    className="@xl/table:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
                                >
                                    <div className="space-y-1">
                                        <p className="font-medium">
                                            {t(row.kind_label)}{' '}
                                            <span className="font-mono text-xs text-muted-foreground">
                                                #{row.source_id}
                                            </span>
                                        </p>
                                        <p className="text-xs wrap-anywhere text-muted-foreground">
                                            {[
                                                t(
                                                    `import.channels.${row.level}`,
                                                ),
                                                ...row.tags,
                                                ...(client ? [client] : []),
                                            ].join(' · ')}
                                        </p>
                                        <ImportOperationBadge
                                            operation={operation}
                                            label={t(`import.${operation}`)}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        {operation !== 'skipped' && (
                                            <>
                                                <p>
                                                    {[
                                                        t(
                                                            row.enabled
                                                                ? 'import.channels.active'
                                                                : 'import.channels.paused',
                                                        ),
                                                        ...row.categories.map(
                                                            (category) =>
                                                                t(
                                                                    `recipient_settings.category.${category}.label`,
                                                                ),
                                                        ),
                                                    ].join(' · ')}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {row.check_types
                                                        .map((type) =>
                                                            t(
                                                                checkTypes[type]
                                                                    .label,
                                                            ),
                                                        )
                                                        .join(' · ')}
                                                </p>
                                            </>
                                        )}
                                        {notices.map((notice) => (
                                            <p
                                                key={notice}
                                                className="text-xs text-muted-foreground"
                                            >
                                                {t(notice)}
                                            </p>
                                        ))}
                                    </div>
                                </ListRow>
                            );
                        })}
                    </div>
                    <ListFooter
                        count={t('import.channels.count', {
                            count: rows.length,
                        })}
                    />
                </>
            )}
        </section>
    );
}

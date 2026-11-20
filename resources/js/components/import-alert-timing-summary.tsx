import { useTranslation } from 'react-i18next';

export function ImportAlertTimingSummary({
    rows,
    counts,
}: {
    rows?: Heptapod.Data.Import.ImportPlanRowData[];
    counts?: { inherited: number; overridden: number };
}) {
    const { t } = useTranslation();
    const inherited =
        counts?.inherited ??
        (rows ?? []).filter((row) => row.inherits_alert_timing === true).length;
    const overridden =
        counts?.overridden ??
        (rows ?? []).filter((row) => row.inherits_alert_timing === false)
            .length;
    if (inherited + overridden === 0) return null;
    return (
        <p className="text-sm text-muted-foreground">
            {t('alert_sensitivity.import_summary', { inherited, overridden })}
        </p>
    );
}

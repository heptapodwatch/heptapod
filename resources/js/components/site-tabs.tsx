import { TooltipHint } from '@/components/truncated-text';
import { Settings } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { StatusDot } from '@/components/monitoring';
import { SectionTabs, type SectionTab } from '@/components/section-tabs';
import { useCheckHeadline } from '@/hooks/use-check-headline';
import { checkState } from '@/lib/status';
import { show as siteShow, settings as siteSettings } from '@/routes/sites';
import { show as checkShow } from '@/routes/sites/checks';

export function SiteTabs({
    siteId,
    checks,
    value,
    canManage = false,
    hrefFor,
}: {
    siteId: number;
    checks: Heptapod.Data.Ui.CheckStripData[];
    value: string;
    canManage?: boolean;
    hrefFor?: (value: string) => string;
}) {
    const { t } = useTranslation();
    const checkHeadline = useCheckHeadline();
    const items: SectionTab[] = [
        {
            value: 'overview',
            order: 0,
            label: t('Overview'),
            href: hrefFor?.('overview') ?? siteShow.url(siteId),
        },
        ...checks.map((check) => ({
            value: String(check.id),
            order: check.id,
            muted: !check.enabled,
            label: (
                <>
                    {(!check.enabled || checkState(check) !== 'ok') && (
                        <StatusDot
                            state={
                                check.enabled ? checkState(check) : 'disabled'
                            }
                            label={
                                check.freshness_suppressed_by
                                    ? 'No current data'
                                    : undefined
                            }
                        />
                    )}
                    {t(check.label_key)}
                    {check.freshness_suppressed_by ? (
                        <TooltipHint content={checkHeadline(check)}>
                            <span className="text-xs text-muted-foreground">
                                · {t('No current data')}
                            </span>
                        </TooltipHint>
                    ) : check.not_fresh ? (
                        <span className="text-xs text-(--status-warn)">
                            · {t('freshness.not_fresh')}
                        </span>
                    ) : null}
                    {!check.enabled && (
                        <span className="text-xs">· {t('Off')}</span>
                    )}
                </>
            ),
            href:
                hrefFor?.(check.key) ??
                checkShow.url({ site: siteId, check: check.id }),
        })),
        ...(canManage
            ? [
                  {
                      value: 'settings',
                      order: Number.MAX_SAFE_INTEGER,
                      separated: true,
                      label: (
                          <>
                              <Settings aria-hidden className="size-3.5" />
                              {t('Settings')}
                          </>
                      ),
                      href: siteSettings.url(siteId),
                  },
              ]
            : []),
    ];
    return (
        <SectionTabs label={t('Site sections')} value={value} items={items} />
    );
}

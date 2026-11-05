import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import type { ComponentProps, ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { PageHeaderActions } from '@/components/page-header-actions';
import { Button } from '@/components/ui/button';
import { RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';

type PageHeaderActionSlots =
    | { actions?: ReactNode; primaryAction?: never; secondaryActions?: never }
    | {
          actions?: never;
          primaryAction?: ReactNode;
          secondaryActions?: ReactNode;
      };

export function PageHeader({
    title,
    titleTooltip,
    headingLevel = 1,
    titleAction,
    leading,
    count,
    badge,
    description,
    metadata,
    trail,
    meta,
    actions,
    primaryAction,
    secondaryActions,
    overflowSecondary,
    secondaryOpen,
    onSecondaryOpenChange,
    secondaryActionCount,
    notices,
    refresh,
}: {
    title: string;
    titleTooltip?: string;
    headingLevel?: 1 | 2;
    titleAction?: ReactNode;
    leading?: ReactNode;
    count?: ReactNode;
    badge?: ReactNode;
    description: string;
    metadata?: ReactNode;
    trail?: ReactNode;
    meta?: ReactNode;
    overflowSecondary?: ComponentProps<
        typeof PageHeaderActions
    >['overflowSecondary'];
    secondaryOpen?: boolean;
    onSecondaryOpenChange?: (open: boolean) => void;
    secondaryActionCount?: number;
    /** Page-level callouts (Alert, one line with an action): below the title and description, never above the h1. */
    notices?: ReactNode;
    /** The one manual refresh: an outline "Refresh" action before the page's other header actions. */
    refresh?: {
        onRefresh: () => void;
        refreshing: boolean;
        disabled?: boolean;
    };
} & PageHeaderActionSlots) {
    const { t } = useTranslation();
    const Heading = headingLevel === 1 ? 'h1' : 'h2';
    return (
        <header data-page-header className="flex min-w-0 flex-col gap-4">
            <div
                className={
                    headingLevel === 1
                        ? 'flex min-w-0 flex-col gap-4 sm:flex-row sm:items-start sm:justify-between'
                        : 'flex min-w-0 flex-col gap-4 md:flex-row md:items-start md:justify-between'
                }
            >
                <div className="min-w-0 space-y-2">
                    {trail !== undefined && (
                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            {trail}
                        </div>
                    )}
                    {metadata}
                    <div className="flex flex-wrap items-center gap-2">
                        {leading}
                        <TooltipHint content={titleTooltip}>
                            <Heading
                                className={
                                    headingLevel === 1
                                        ? 'min-w-0 text-page-title break-words'
                                        : 'min-w-0 text-headline break-words'
                                }
                                aria-label={
                                    titleAction === undefined
                                        ? undefined
                                        : title
                                }
                            >
                                {title}
                                {titleAction !== undefined && (
                                    <> {titleAction}</>
                                )}
                            </Heading>
                        </TooltipHint>
                        {count !== undefined && (
                            <Badge
                                variant="secondary"
                                className="font-mono tabular-nums"
                            >
                                {count}
                            </Badge>
                        )}
                        {badge}
                    </div>
                    {meta !== undefined && (
                        <div className="text-sm text-muted-foreground">
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                {meta}
                            </div>
                        </div>
                    )}
                    {description && (
                        <TruncatedText content={description}>
                            <p className="line-clamp-2 text-sm text-muted-foreground sm:line-clamp-1">
                                {description}
                            </p>
                        </TruncatedText>
                    )}
                </div>
                {(primaryAction || secondaryActions) && (
                    <PageHeaderActions
                        primary={primaryAction}
                        secondary={secondaryActions}
                        fullWidthOnMobile={headingLevel === 2}
                        overflowSecondary={overflowSecondary}
                        secondaryOpen={secondaryOpen}
                        onSecondaryOpenChange={onSecondaryOpenChange}
                        secondaryActionCount={secondaryActionCount}
                    />
                )}
                {(actions || refresh) && (
                    <div className="flex shrink-0 flex-wrap items-center gap-2 sm:ml-auto sm:max-w-[60%] sm:justify-end">
                        {refresh && (
                            <Button
                                variant="outline"
                                disabled={
                                    refresh.refreshing || refresh.disabled
                                }
                                onClick={refresh.onRefresh}
                            >
                                <RefreshCw
                                    aria-hidden="true"
                                    className={
                                        refresh.refreshing
                                            ? 'animate-spin'
                                            : undefined
                                    }
                                />
                                {t(
                                    refresh.refreshing
                                        ? 'Refreshing…'
                                        : 'Refresh',
                                )}
                            </Button>
                        )}
                        {actions}
                    </div>
                )}
            </div>
            {/* empty:hidden: a notices node that renders nothing (no current notice) takes no gap. */}
            {notices !== undefined && (
                <div
                    data-page-notices
                    className="flex min-w-0 flex-col gap-2 empty:hidden"
                >
                    {notices}
                </div>
            )}
        </header>
    );
}

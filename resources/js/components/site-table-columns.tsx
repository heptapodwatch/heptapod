import { Settings2 } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/** Columns of the Sites table a user can hide; Status and Site always show. */
export const hideableSiteColumns = [
    ['client_id', 'Client'],
    ['why', 'Why'],
    ['checks', 'Checks'],
    ['list_scores', 'checks.lighthouse.label'],
    ['response', '24h response'],
    ['uptime', '30-day uptime'],
    ['checked_at', 'Last checked'],
] as const;
export type HideableSiteColumn = (typeof hideableSiteColumns)[number][0];

// A cookie, like the sidebar state: it lives in this browser, needs no account write, and the server could read it.
const cookieName = 'sites_hidden_columns';
const listeners = new Set<() => void>();

function readCookie(): string {
    return (
        document.cookie
            .split('; ')
            .find((part) => part.startsWith(`${cookieName}=`))
            ?.slice(cookieName.length + 1) ?? ''
    );
}

/** The columns this browser hid; unknown ids from an older cookie are ignored. */
export function useHiddenSiteColumns(): ReadonlySet<HideableSiteColumn> {
    const raw = useSyncExternalStore(
        (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        readCookie,
        () => '',
    );
    return new Set(
        decodeURIComponent(raw)
            .split(',')
            .filter((id): id is HideableSiteColumn =>
                hideableSiteColumns.some(([column]) => column === id),
            ),
    );
}

function writeHiddenColumns(hidden: ReadonlySet<HideableSiteColumn>) {
    document.cookie = `${cookieName}=${encodeURIComponent([...hidden].join(','))}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
    listeners.forEach((listener) => listener());
}

/** The gear in the Sites table header (and above the cards below 600 px): a checkbox per hideable column and Reset. */
export function SiteColumnsMenu({
    columns,
}: {
    /** The hideable columns this table has (the client page has no Client column). */
    columns: readonly HideableSiteColumn[];
}) {
    const { t } = useTranslation();
    const hidden = useHiddenSiteColumns();
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-xs"
                    aria-label={t('Columns')}
                >
                    <Settings2 />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-52">
                <DropdownMenuLabel>{t('Show columns')}</DropdownMenuLabel>
                {hideableSiteColumns
                    .filter(([column]) => columns.includes(column))
                    .map(([column, label]) => (
                        <DropdownMenuCheckboxItem
                            key={column}
                            checked={!hidden.has(column)}
                            // The menu stays open, so several columns change in one go.
                            onSelect={(event) => event.preventDefault()}
                            onCheckedChange={(checked) => {
                                const next = new Set(hidden);
                                if (checked) next.delete(column);
                                else next.add(column);
                                writeHiddenColumns(next);
                            }}
                        >
                            {t(label)}
                        </DropdownMenuCheckboxItem>
                    ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    disabled={hidden.size === 0}
                    onSelect={() => writeHiddenColumns(new Set())}
                >
                    {t('Reset columns')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

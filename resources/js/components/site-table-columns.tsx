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

/** The columns this browser chose to show (true) or hide (false); a column without a choice keeps its default. */
export type SiteColumnChoices = ReadonlyMap<HideableSiteColumn, boolean>;

// A cookie, like the sidebar state: it lives in this browser, needs no account write, and the server could read it.
// Entries are `column:1` / `column:0`; a bare id (the first format) means hidden.
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

function isColumn(id: string): id is HideableSiteColumn {
    return hideableSiteColumns.some(([column]) => column === id);
}

/** This browser's column choices; unknown ids from an older cookie are ignored. */
export function useSiteColumnChoices(): SiteColumnChoices {
    const raw = useSyncExternalStore(
        (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        readCookie,
        () => '',
    );
    const choices = new Map<HideableSiteColumn, boolean>();
    for (const entry of decodeURIComponent(raw).split(',')) {
        const [id = '', shown] = entry.split(':');
        if (isColumn(id)) choices.set(id, shown === '1');
    }
    return choices;
}

function writeChoices(choices: SiteColumnChoices) {
    const value = [...choices]
        .map(([column, shown]) => `${column}:${shown ? 1 : 0}`)
        .join(',');
    document.cookie = `${cookieName}=${encodeURIComponent(value)}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
    listeners.forEach((listener) => listener());
}

/**
 * The gear in the Sites table's own last header cell (above the cards below 600 px): a checkbox per hideable column
 * and Reset, which returns every column to its default.
 */
export function SiteColumnsMenu({
    columns,
    choices,
    shows,
}: {
    /** The hideable columns this table has (the client page has no Client column). */
    columns: readonly HideableSiteColumn[];
    choices: SiteColumnChoices;
    /** Whether a column shows now: the choice, else its default. */
    shows: (column: HideableSiteColumn) => boolean;
}) {
    const { t } = useTranslation();
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
                            checked={shows(column)}
                            // The menu stays open, so several columns change in one go.
                            onSelect={(event) => event.preventDefault()}
                            onCheckedChange={(checked) =>
                                writeChoices(
                                    new Map(choices).set(column, checked),
                                )
                            }
                        >
                            {t(label)}
                        </DropdownMenuCheckboxItem>
                    ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    disabled={choices.size === 0}
                    onSelect={() => writeChoices(new Map())}
                >
                    {t('Reset columns')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

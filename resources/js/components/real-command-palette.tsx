import { ClientMark } from '@/components/client-mark';
import { SiteAvatar } from '@/components/site-avatar';
import { router } from '@inertiajs/react';
import {
    Keyboard,
    LogOut,
    Monitor,
    Moon,
    Plus,
    Search,
    Sun,
} from 'lucide-react';
import { useEffect, useRef, useState, type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/monitoring';
import { siteHost } from '@/components/entity-combobox';
import { Button } from '@/components/ui/button';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
    CommandShortcut,
} from '@/components/ui/command';
import { Kbd } from '@/components/ui/kbd';
import { cn } from '@/lib/utils';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { useFormat } from '@/hooks/use-format';
import type { NavigationEntry } from '@/lib/navigation';
import { logout, search as searchRoute } from '@/routes';
import { show as siteShow, create as sitesCreate } from '@/routes/sites';
import { show as clientShow } from '@/routes/clients';
import { show as incidentShow } from '@/routes/incidents';

const empty: Heptapod.Data.Ui.SearchResultsData = {
    sites: [],
    clients: [],
    incidents: [],
};

export function CommandPaletteTrigger({
    onClick,
    compact = false,
    className,
}: {
    onClick: (trigger: HTMLButtonElement) => void;
    compact?: boolean;
    className?: string;
}) {
    const { t } = useTranslation();
    return (
        <Button
            variant="outline"
            size={compact ? 'icon' : 'default'}
            className={cn(
                !compact &&
                    'w-full justify-start gap-2 px-3 group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:p-0',
                className,
            )}
            aria-label={t(compact ? 'Open command palette' : 'Search')}
            aria-haspopup="dialog"
            data-command-palette-trigger={compact ? 'mobile' : 'sidebar'}
            onClick={(event) => onClick(event.currentTarget)}
        >
            <Search />
            {!compact && (
                <>
                    <span className="min-w-0 flex-1 truncate text-left text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
                        {t('Search')}
                    </span>
                    <Kbd className="group-data-[collapsible=icon]:hidden">
                        ⌘K
                    </Kbd>
                </>
            )}
        </Button>
    );
}

export function RealCommandPalette({
    open,
    onOpenChange: setOpen,
    onOpen,
    onCloseAutoFocus,
    destinations,
    onShowShortcuts,
}: {
    open: boolean;
    onOpenChange: (open: boolean | ((current: boolean) => boolean)) => void;
    onOpen: (element: Element | null) => void;
    onCloseAutoFocus: NonNullable<
        ComponentProps<typeof CommandDialog>['onCloseAutoFocus']
    >;
    /** Visible navigation destinations; the palette offers "Go to" for each href once. */
    destinations: NavigationEntry[];
    onShowShortcuts: () => void;
}) {
    const { t } = useTranslation();
    const { updateAppearance } = useAppearance();
    const goTo = destinations.reduce<
        { entry: NavigationEntry; keywords: string[] }[]
    >((commands, entry) => {
        const existing = commands.find(
            (command) => command.entry.href === entry.href,
        );
        if (existing) existing.keywords.push(t(entry.title));
        else commands.push({ entry, keywords: [] });
        return commands;
    }, []);
    const themes: { value: Appearance; label: string; icon: typeof Sun }[] = [
        { value: 'light', label: 'Light', icon: Sun },
        { value: 'dark', label: 'Dark', icon: Moon },
        { value: 'system', label: 'System', icon: Monitor },
    ];
    const close = () => {
        setOpen(false);
        setQuery('');
    };
    const format = useFormat();
    const latestOpen = useRef(open);
    latestOpen.current = open;
    const [query, setQuery] = useState('');
    const [results, setResults] = useState(empty);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const [retryCount, setRetryCount] = useState(0);
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                if (!latestOpen.current) onOpen(document.activeElement);
                setOpen((value) => !value);
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [setOpen, onOpen]);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setLoading(true);
            setError(false);
            try {
                const response = await fetch(
                    searchRoute.url({ query: { q: query.trim() } }),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );
                if (!response.ok)
                    throw new Error(
                        'Search request failed: ' + response.status,
                    );
                const data: Heptapod.Data.Ui.SearchResultsData =
                    await response.json();
                setResults(data);
            } catch (failure) {
                if (!controller.signal.aborted) {
                    console.error(failure);
                    setError(true);
                }
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 150);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, retryCount]);
    const navigate = (url: string) => {
        setOpen(false);
        setQuery('');
        router.visit(url);
    };
    return (
        <>
            <CommandDialog
                open={open}
                onOpenChange={setOpen}
                onCloseAutoFocus={onCloseAutoFocus}
                title={t('Command palette')}
                description={t('Search sites, clients, actions…')}
            >
                <CommandInput
                    value={query}
                    onValueChange={setQuery}
                    placeholder={t('Search sites, clients, actions…')}
                />
                <CommandList className="h-[min(300px,50dvh)] shrink-0">
                    {/* Local commands stay mounted while the search request runs, so cmdk ranks them on every keystroke. */}
                    {!loading && !error && (
                        <CommandEmpty>
                            {t('No matches. Try another search.')}
                        </CommandEmpty>
                    )}
                    {/* cmdk 1.1 does not reorder groups by score (its group lookup misses the generated id), so
                        destinations come first, as in the sidebar; items still rank within each group. */}
                    <CommandGroup heading={t('Go to')}>
                        {goTo.map(({ entry, keywords }) => (
                            <CommandItem
                                key={entry.href}
                                value={`${t('Go to')} ${t(entry.title)}`}
                                keywords={keywords}
                                onSelect={() => navigate(entry.href)}
                            >
                                <entry.icon />
                                {t(entry.title)}
                                {entry.shortcut && (
                                    <CommandShortcut>
                                        G {entry.shortcut.toUpperCase()}
                                    </CommandShortcut>
                                )}
                            </CommandItem>
                        ))}
                    </CommandGroup>
                    <CommandGroup heading={t('Actions')}>
                        <CommandItem
                            value={t('Add site')}
                            onSelect={() => navigate(sitesCreate.url())}
                        >
                            <Plus />
                            {t('Add site')}
                        </CommandItem>
                        {themes.map((theme) => (
                            <CommandItem
                                key={theme.value}
                                value={`${t('Theme')}: ${t(theme.label)}`}
                                onSelect={() => {
                                    updateAppearance(theme.value);
                                    close();
                                }}
                            >
                                <theme.icon />
                                {t('Theme')}: {t(theme.label)}
                            </CommandItem>
                        ))}
                        <CommandItem
                            value={t('Keyboard shortcuts')}
                            onSelect={() => {
                                close();
                                onShowShortcuts();
                            }}
                        >
                            <Keyboard />
                            {t('Keyboard shortcuts')}
                            <CommandShortcut>?</CommandShortcut>
                        </CommandItem>
                        <CommandItem
                            value={t('Log out')}
                            onSelect={() => {
                                close();
                                router.flushAll();
                                router.post(logout.url());
                            }}
                        >
                            <LogOut />
                            {t('Log out')}
                        </CommandItem>
                    </CommandGroup>
                    {error ? (
                        <div
                            role="alert"
                            className="flex items-center justify-between gap-3 p-3 text-sm text-foreground"
                        >
                            <span>{t('Search unavailable')}</span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setRetryCount((count) => count + 1)
                                }
                            >
                                {t('Retry')}
                            </Button>
                        </div>
                    ) : loading ? (
                        <p
                            role="status"
                            className="p-3 text-sm text-muted-foreground"
                        >
                            {t('Searching…')}
                        </p>
                    ) : (
                        <>
                            {(results.sites.length > 0 ||
                                results.clients.length > 0 ||
                                results.incidents.length > 0) && (
                                <CommandSeparator />
                            )}
                            {results.sites.length > 0 && (
                                <CommandGroup heading={t('Sites')}>
                                    {results.sites.map((site) => (
                                        <CommandItem
                                            key={site.id}
                                            value={`${site.name} ${site.url}`}
                                            onSelect={() =>
                                                navigate(siteShow.url(site.id))
                                            }
                                        >
                                            <SiteAvatar
                                                name={site.name}
                                                iconUrl={site.icon_url}
                                                iconBacking={site.icon_backing}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate">
                                                    {site.name}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {[
                                                        site.client_name,
                                                        siteHost(site.url),
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            </span>
                                            <StatusBadge state={site.state} />
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}
                            {results.clients.length > 0 && (
                                <CommandGroup heading={t('Clients')}>
                                    {results.clients.map((client) => (
                                        <CommandItem
                                            key={client.id}
                                            value={client.name}
                                            onSelect={() =>
                                                navigate(
                                                    clientShow.url(client.id),
                                                )
                                            }
                                        >
                                            <span className="inline-flex items-center gap-2">
                                                <ClientMark
                                                    client={client}
                                                    small
                                                />
                                                {client.name}
                                            </span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}
                            {results.incidents.length > 0 && (
                                <CommandGroup heading={t('Incidents')}>
                                    {results.incidents.map((incident) => (
                                        <CommandItem
                                            key={incident.id}
                                            value={`${incident.site_name} INC-${incident.id}`}
                                            onSelect={() =>
                                                navigate(
                                                    incidentShow.url(
                                                        incident.id,
                                                    ),
                                                )
                                            }
                                        >
                                            <SiteAvatar
                                                name={incident.site_name}
                                                iconUrl={incident.icon_url}
                                                iconBacking={
                                                    incident.icon_backing
                                                }
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate">
                                                    INC-{incident.id} ·{' '}
                                                    {incident.site_name}
                                                </span>
                                                <span className="line-clamp-2 text-xs text-muted-foreground">
                                                    {format.issueHeadline(
                                                        {
                                                            ...incident,
                                                            fallback:
                                                                incident.summary
                                                                    ? format.summary(
                                                                          incident.summary,
                                                                          incident.summary_params,
                                                                      )
                                                                    : t(
                                                                          incident.cause ===
                                                                              'errored'
                                                                              ? 'Check could not run'
                                                                              : 'Check failed',
                                                                      ),
                                                        },
                                                        true,
                                                    )}
                                                </span>
                                            </span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}
                        </>
                    )}
                </CommandList>
                <div className="flex items-center gap-2 border-t p-3 text-xs text-muted-foreground">
                    <Kbd>↑ ↓</Kbd>
                    {t('Navigate')}
                    <Kbd>↵</Kbd>
                    {t('Open')}
                    <Kbd>Esc</Kbd>
                    {t('Close')}
                </div>
            </CommandDialog>
        </>
    );
}

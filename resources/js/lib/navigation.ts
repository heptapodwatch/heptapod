import { edit as profileEdit } from '@/routes/profile';
import { index as runsIndex } from '@/routes/runs';
import { edit as securityEdit } from '@/routes/security';
import { edit as alertsEdit } from '@/routes/settings/alerts';
import { edit as apiTokensEdit } from '@/routes/settings/api-tokens';
import { edit as importEdit } from '@/routes/settings/import/ohdear';
import { edit as instanceEdit } from '@/routes/settings/instance';
import { index as membersIndex } from '@/routes/settings/members';
import {
    edit as notificationsEdit,
    team as teamNotifications,
} from '@/routes/settings/notifications';
import { edit as systemEdit } from '@/routes/settings/system';
import { edit as updatesEdit } from '@/routes/settings/updates';
import type { Auth } from '@/types';
import {
    Activity,
    Bell,
    BellRing,
    CalendarDays,
    Gauge,
    Globe,
    Import,
    KeyRound,
    RefreshCw,
    RotateCw,
    Server,
    Settings,
    Shield,
    Siren,
    SlidersHorizontal,
    UserRound,
    Users,
    UsersRound,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

/** App sidebar groups, then the settings sidebar groups. */
export type NavigationGroup =
    | 'Primary'
    | 'Planning'
    | 'Operations'
    | 'Footer'
    | 'Account'
    | 'Team'
    | 'Instance';

export type NavigationEntry = {
    /** Translation key; the nav label and the destination's h1 use the same key. */
    title: string;
    href: string;
    icon: LucideIcon;
    group: NavigationGroup;
    /** Shared `auth` flag that must be true for the entry to show. */
    permission?: Exclude<keyof Auth, 'user'>;
    /** Path prefix the entry owns; the longest matching entry is the active one. */
    match?: string;
    /** Second key of the `G` then key navigation shortcut. */
    shortcut?: string;
};

/**
 * Every navigation destination, once, in priority order. Sidebar, settings sidebar, command palette and shortcuts read this
 * list: the daily monitoring views first, then Planning & history, then Operations.
 */
export const navigation: NavigationEntry[] = [
    {
        title: 'Sites',
        href: '/sites',
        icon: Globe,
        group: 'Primary',
        shortcut: 's',
    },
    {
        title: 'Incidents',
        href: '/incidents',
        icon: Siren,
        group: 'Primary',
        shortcut: 'i',
    },
    {
        title: 'Clients',
        href: '/clients',
        icon: Users,
        group: 'Primary',
        shortcut: 'c',
    },
    {
        title: 'Maintenance',
        href: '/maintenance',
        icon: Wrench,
        group: 'Planning',
        shortcut: 'm',
    },
    {
        title: 'Activity',
        href: '/activity',
        icon: Activity,
        group: 'Planning',
        shortcut: 'a',
    },
    {
        title: 'Upcoming',
        href: '/upcoming',
        icon: CalendarDays,
        group: 'Planning',
        shortcut: 'u',
    },
    {
        title: 'Runs',
        href: runsIndex.url(),
        icon: RotateCw,
        group: 'Operations',
        shortcut: 'r',
    },
    {
        title: 'System health',
        href: systemEdit.url(),
        icon: Server,
        group: 'Operations',
        permission: 'canViewSystemHealth',
        shortcut: 'h',
    },
    {
        title: 'Updates & backups',
        href: updatesEdit.url(),
        icon: RefreshCw,
        group: 'Operations',
        permission: 'canViewUpdates',
        shortcut: 'b',
    },
    {
        title: 'Settings',
        href: profileEdit.url(),
        icon: Settings,
        group: 'Footer',
        match: '/settings',
    },
    {
        title: 'Profile',
        href: profileEdit.url(),
        icon: UserRound,
        group: 'Account',
    },
    {
        title: 'My notifications',
        href: notificationsEdit.url(),
        icon: Bell,
        group: 'Account',
    },
    {
        title: 'Security',
        href: securityEdit.url(),
        icon: Shield,
        group: 'Account',
    },
    {
        title: 'API tokens',
        href: apiTokensEdit.url(),
        icon: KeyRound,
        group: 'Account',
    },
    {
        title: 'Members',
        href: membersIndex.url(),
        icon: UsersRound,
        group: 'Team',
        permission: 'canManageMembers',
    },
    {
        title: 'Import',
        href: importEdit.url(),
        icon: Import,
        group: 'Team',
        permission: 'canManageMembers',
    },
    {
        title: 'Notifications',
        href: teamNotifications.url(),
        icon: BellRing,
        group: 'Team',
        permission: 'canManageChannels',
    },
    {
        title: 'alert_sensitivity.title',
        href: alertsEdit.url(),
        icon: Gauge,
        group: 'Team',
        permission: 'canManageChannels',
    },
    {
        title: 'General',
        href: instanceEdit.url(),
        icon: SlidersHorizontal,
        group: 'Instance',
        permission: 'canManageInstance',
    },
];

export const settingsGroups = ['Account', 'Team', 'Instance'] as const;

export const appSidebarNavigation = navigation.filter(
    (entry) =>
        entry.group === 'Primary' ||
        entry.group === 'Planning' ||
        entry.group === 'Operations' ||
        entry.group === 'Footer',
);

export function visibleNavigation(auth: Auth): NavigationEntry[] {
    return navigation.filter(
        (entry) => entry.permission === undefined || auth[entry.permission],
    );
}

function owns(prefix: string, path: string): boolean {
    return path === prefix || path.startsWith(`${prefix}/`);
}

/** The entry whose path prefix matches the current path most specifically, so System health wins over Settings on /settings/system. */
export function activeNavigationEntry(
    entries: NavigationEntry[],
    path: string,
): NavigationEntry | undefined {
    let active: NavigationEntry | undefined;
    for (const entry of entries) {
        const prefix = entry.match ?? entry.href;
        if (
            owns(prefix, path) &&
            (active === undefined ||
                prefix.length > (active.match ?? active.href).length)
        ) {
            active = entry;
        }
    }
    return active;
}

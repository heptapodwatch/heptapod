import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            csrfToken?: string;
            mailLogOnly: boolean;
            mailConfigurationAction: { action_key: string; href: string };
            mailMayOnlyBeLogged: boolean;
            locale: 'en' | 'de';
            timezone: string;
            /** Rows one export holds (Table::RowLimit, app/Contracts/Export). */
            exportRowLimit: number;
            /** The public repository (AGPL); the user menu links to it. */
            sourceUrl: string;
            /** Only on pages with a zone field (Inertia `->with`): the default an empty zone follows. */
            instanceTimezone?: string;
            auth: Auth;
            sidebarOpen: boolean;
            liveChannels: string[];
            overallHealth: Heptapod.Data.Ui.ShellHealthData | null;
            /** The installed version; null for guests. */
            heptapodVersion: string | null;
            /** Owners and Admins only; `latest` is set only while an update is available. */
            appUpdate: {
                status: string;
                latest: string | null;
                security: boolean;
            } | null;
            lateRunsCount?: number;
            runningNow?: Heptapod.Data.Ui.RunningNowData | null;
            notificationAttention: {
                id: number;
                label: string;
                reason: string;
            }[];
        };
    }
}

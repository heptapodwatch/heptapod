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
            /** Only on pages with a zone field (Inertia `->with`): the default an empty zone follows. */
            instanceTimezone?: string;
            auth: Auth;
            sidebarOpen: boolean;
            liveChannels: string[];
            overallHealth: Heptapod.Data.Ui.ShellHealthData | null;
            lateRunsCount?: number;
            notificationAttention: {
                id: number;
                label: string;
                reason: string;
            }[];
        };
    }
}

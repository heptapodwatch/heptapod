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
            name: string;
            mailLogOnly: boolean;
            mailConfigurationAction: { action_key: string; href: string };
            mailMayOnlyBeLogged: boolean;
            locale: 'en' | 'de';
            timezone: string;
            auth: Auth;
            sidebarOpen: boolean;
            liveChannels: string[];
            overallHealth: Heptapod.Data.Ui.ShellHealthData | null;
            lateRunsCount?: number;
            notificationAttention: {
                id: number;
                label: string;
                channel: string;
                reason: string;
            }[];
            [key: string]: unknown;
        };
    }
}

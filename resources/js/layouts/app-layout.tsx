import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { AppLayoutProps } from '@/types';

export default function AppLayout({
    children,
    incidentBanner,
}: AppLayoutProps) {
    return (
        <AppLayoutTemplate incidentBanner={incidentBanner}>
            {children}
        </AppLayoutTemplate>
    );
}

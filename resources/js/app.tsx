import { createInertiaApp, router } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';
import '@/lib/i18n';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { reverbClient } from '@/lib/live-store';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = 'Heptapod';
let pendingNavigation: {
    url: string;
    id: string | undefined;
    at: number;
} | null = null;

function navigationUrlWithoutHash(url: string): string {
    const target = new URL(url, location.href);
    target.hash = '';
    return target.toString();
}

router.on('before', (event) => {
    const visit = event.detail.visit;
    if (visit.prefetch || visit.async) return;
    pendingNavigation = null;
    if (
        visit.method === 'get' &&
        visit.only.length === 0 &&
        visit.except.length === 0 &&
        (visit.reset?.length ?? 0) === 0
    ) {
        const target = new URL(visit.url.toString(), location.href);
        if (
            ['http:', 'https:'].includes(target.protocol) &&
            target.origin === location.origin
        ) {
            pendingNavigation = {
                url: target.toString(),
                id: visit.id,
                at: Date.now(),
            };
        }
    }
});
router.on('navigate', (event) => {
    if (
        pendingNavigation &&
        (event.detail.visitId === pendingNavigation.id ||
            (event.detail.visitId === undefined &&
                navigationUrlWithoutHash(event.detail.page.url) ===
                    navigationUrlWithoutHash(pendingNavigation.url)))
    ) {
        pendingNavigation = null;
    }
});
router.on('success', (event) => {
    if (pendingNavigation && event.detail.visitId === pendingNavigation.id) {
        pendingNavigation = null;
    }
});
router.on('httpException', () => {
    pendingNavigation = null;
});
router.on('cancel', () => {
    pendingNavigation = null;
});
router.on('finish', (event) => {
    if (
        pendingNavigation &&
        (event.detail.visit.cancelled || event.detail.visit.interrupted) &&
        event.detail.visit.id === pendingNavigation.id
    ) {
        pendingNavigation = null;
    }
});

function recoverNavigation(failedUrl?: string): boolean {
    const target = pendingNavigation;
    if (!target || Date.now() - target.at > 30_000) return false;
    if (
        failedUrl &&
        navigationUrlWithoutHash(failedUrl) !==
            navigationUrlWithoutHash(target.url)
    )
        return false;
    const key = `heptapod:navigation-retry:${target.url}`;
    if (Date.now() - Number(sessionStorage.getItem(key)) < 60_000) return false;
    sessionStorage.setItem(key, String(Date.now()));
    window.location.assign(target.url);
    return true;
}

window.addEventListener('vite:preloadError', (event) => {
    if (recoverNavigation()) event.preventDefault();
});
router.on('networkError', (event) => {
    const error = event.detail.error;
    if (
        recoverNavigation(
            'url' in error && typeof error.url === 'string'
                ? error.url
                : undefined,
        )
    ) {
        event.preventDefault();
    }
});

configureEcho(
    reverbClient
        ? {
              broadcaster: 'reverb',
              key: reverbClient.key,
              wsHost: reverbClient.host,
              wsPort: reverbClient.port,
              wssPort: reverbClient.port,
              wsPath: reverbClient.path,
              forceTLS: reverbClient.scheme === 'https',
              enabledTransports: ['ws', 'wss'],
          }
        : { broadcaster: 'null' },
);

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            // System health and Updates & backups are Operations destinations, not settings.
            case name === 'settings/system' || name === 'settings/updates':
                return AppLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

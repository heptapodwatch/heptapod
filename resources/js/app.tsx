import { createInertiaApp, router } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';
import i18n from 'i18next';
import { toast } from 'sonner';
import { i18nReady } from '@/lib/i18n';
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
// A write that reached the server drops every prefetched page, in flight ones included, so a hover that read before the
// write cannot bring the old state back: the user's own change shows at once. A refused write flushes too, harmlessly.
router.on('finish', (event) => {
    const visit = event.detail.visit;
    if (
        !['get', 'query'].includes(visit.method) &&
        visit.completed &&
        !visit.cancelled
    )
        router.flushAll();
});
router.on('httpException', (event) => {
    pendingNavigation = null;
    const response = event.detail.response;
    // Inertia responses (e.g. pages/errors/show) render inside AppLayout; never suppress them.
    if (response.headers['x-inertia'] !== undefined) {
        return;
    }
    // Non-Inertia HTML/text responses (500/503 Blade, proxy 502/504, disabled-account 401, Nginx 413) must never open
    // Inertia's raw iframe modal; show a translated notice instead.
    event.preventDefault();
    const status = response.status;
    if (status === 401) {
        toast.error(i18n.t('errors.401.description'), {
            action: {
                label: i18n.t('Sign in'),
                onClick: () => window.location.assign('/login'),
            },
        });
    } else if (status === 419) {
        toast.warning(i18n.t('errors.419.refreshed'));
    } else if (status === 413) {
        toast.warning(i18n.t('errors.413.upload'));
    } else if (status === 429) {
        toast.warning(i18n.t('errors.429.description'));
    } else if (status === 502 || status === 503 || status === 504) {
        toast.warning(i18n.t('errors.503.action'));
    } else {
        toast.error(i18n.t('errors.500.action'));
    }
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
        return;
    }
    event.preventDefault();
    toast.warning(i18n.t('errors.network'));
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

void i18nReady.then(() =>
    createInertiaApp({
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
        defaults: {
            // A hovered link's page is fresh for 10 s; up to a minute a visit shows it at once while a hover refetches it.
            prefetch: { cacheFor: ['10s', '1m'] },
        },
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
    }),
);

// This will set light / dark mode on load...
initializeTheme();

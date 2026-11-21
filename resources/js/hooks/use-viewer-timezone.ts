import { usePage } from '@inertiajs/react';

/** The signed-in viewer's resolved timezone (user → instance), or the instance timezone for guests. */
export function useViewerTimezone(): string {
    return usePage().props.timezone;
}

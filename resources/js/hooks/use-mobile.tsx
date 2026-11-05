import { useSyncExternalStore } from 'react';

const MOBILE_BREAKPOINT = 768;

const mql =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);

function mediaQueryListener(callback: (event: MediaQueryListEvent) => void) {
    if (!mql) {
        return () => {};
    }

    mql.addEventListener('change', callback);

    return () => {
        mql.removeEventListener('change', callback);
    };
}

function isSmallerThanBreakpoint(): boolean {
    return mql?.matches ?? false;
}

function getServerSnapshot(): boolean {
    return false;
}

export function useIsMobile(): boolean {
    return useSyncExternalStore(
        mediaQueryListener,
        isSmallerThanBreakpoint,
        getServerSnapshot,
    );
}

const smMql =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia('(max-width: 639px)');

/** Below Tailwind's sm breakpoint (640 px): where card toolbars fold their actions into one menu. */
export function useIsBelowSm(): boolean {
    return useSyncExternalStore(
        (callback) => {
            smMql?.addEventListener('change', callback);
            return () => smMql?.removeEventListener('change', callback);
        },
        () => smMql?.matches ?? false,
        getServerSnapshot,
    );
}

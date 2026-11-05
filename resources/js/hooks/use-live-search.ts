import type { VisitOptions } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export type LiveSearchVisitOptions = Pick<
    VisitOptions,
    'onBeforeUpdate' | 'onCancelToken'
>;

export function useLiveSearch(
    value: string,
    apply: (search: string, options: LiveSearchVisitOptions) => void,
) {
    const { url } = usePage();
    const [search, setSearch] = useState(value);
    const timer = useRef<number | undefined>(undefined);
    const debounceUrl = useRef<string | undefined>(undefined);
    const navigationUrl = (url: string) => {
        const parsed = new URL(url, window.location.origin);
        return parsed.pathname + parsed.search;
    };
    const cancelVisit = useRef<(() => void) | undefined>(undefined);
    const ownResponse = useRef<string | undefined>(undefined);
    const clearTimer = () => {
        window.clearTimeout(timer.current);
        timer.current = undefined;
        debounceUrl.current = undefined;
    };
    const cancelPendingSearch = () => {
        clearTimer();
        cancelVisit.current?.();
    };
    useEffect(() => {
        const submitted = ownResponse.current;
        ownResponse.current = undefined;
        // Own responses preserve unfinished text; Back restores the URL's search.
        setSearch((current) =>
            submitted === value && current.trim() === value ? current : value,
        );
    }, [value, url]);
    useEffect(() => {
        const removeStart = router.on('start', ({ detail }) => {
            if (!detail.visit.async) {
                clearTimer();
                ownResponse.current = undefined;
            }
        });
        const removeNavigate = router.on('navigate', ({ detail }) => {
            // Deferred and live props refresh the same URL while the user is still typing.
            if (
                debounceUrl.current !== undefined &&
                navigationUrl(detail.page.url) !== debounceUrl.current
            ) {
                clearTimer();
            }
        });
        return () => {
            cancelPendingSearch();
            removeStart();
            removeNavigate();
        };
    }, []);
    const visit = (next: string) => {
        clearTimer();
        apply(next, {
            onBeforeUpdate: () => {
                ownResponse.current = next.trim();
            },
            onCancelToken: (token) => {
                cancelVisit.current = token.cancel;
            },
        });
    };
    const changeSearch = (next: string) => {
        cancelPendingSearch();
        setSearch(next);
        if (next === '') visit(next);
        else {
            debounceUrl.current = navigationUrl(url);
            timer.current = window.setTimeout(() => visit(next), 250);
        }
    };
    return { search, changeSearch, cancelPendingSearch };
}

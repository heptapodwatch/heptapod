import { useRef } from 'react';

/** Overlay surfaces: a control inside one that is closing is never an opener to return to. */
const overlaySelector =
    '[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]';

/** The region an opener belongs to: its card, form section or page section, else the page's main landmark. */
const regionSelector =
    '[data-slot="card"], [data-form-section], section, [role="region"], main';

/** The last element focused outside every overlay; the opener when an overlay opens as another one closes. */
let lastFocusOutsideOverlays: HTMLElement | null = null;
/**
 * Page departures, from Inertia's own events. A dialog closed while the app leaves the page hands focus to the new
 * page; a request that stays on the page (a rejected submit, a same-page save, a partial reload) never counts.
 * - `departures` counts real page changes: a `navigate` whose path or component differs from the page before it.
 * - `leaving` holds the path of an in-flight GET visit to another page (the palette closes before its response arrives).
 */
let departures = 0;
/** The path of the in-flight GET visit to another page, or null. */
let leaving: string | null = null;
let currentPage: { path: string; component: string | null } | null = null;

function pathOf(url: string): string {
    return new URL(url, window.location.href).pathname;
}

if (typeof document !== 'undefined') {
    document.addEventListener(
        'focusin',
        (event) => {
            if (
                event.target instanceof HTMLElement &&
                !event.target.closest(overlaySelector)
            )
                lastFocusOutsideOverlays = event.target;
        },
        true,
    );
    document.addEventListener('inertia:before', (event) => {
        const visit = (
            event as CustomEvent<{
                visit: {
                    url: URL | string;
                    method: string;
                    only: unknown[];
                    prefetch?: boolean;
                };
            }>
        ).detail.visit;
        // A listener after this one may cancel the visit; decide once dispatch has finished.
        queueMicrotask(() => {
            if (event.defaultPrevented) return;
            // Hover prefetches announce themselves the same way but never leave the page.
            if (visit.prefetch) return;
            const path = pathOf(String(visit.url));
            leaving =
                visit.method === 'get' &&
                visit.only.length === 0 &&
                path !== (currentPage?.path ?? window.location.pathname)
                    ? path
                    : null;
        });
    });
    document.addEventListener('inertia:navigate', (event) => {
        const page = (
            event as CustomEvent<{ page: { url: string; component: string } }>
        ).detail.page;
        const next = { path: pathOf(page.url), component: page.component };
        if (
            currentPage !== null &&
            (currentPage.path !== next.path ||
                currentPage.component !== next.component)
        )
            departures++;
        currentPage = next;
        leaving = null;
    });
    // A failed or cancelled departure stays on the page; a live update finishing meanwhile does not end it.
    document.addEventListener('inertia:finish', (event) => {
        const visit = (event as CustomEvent<{ visit: { url: URL | string } }>)
            .detail.visit;
        if (leaving !== null && pathOf(String(visit.url)) === leaving)
            leaving = null;
    });
}

/**
 * What focus returns to when an overlay closes: the focused element, or for a menu item (which closes with its menu) the
 * menu's trigger. When focus already left with a closing overlay (a palette command, a sheet that closes as this
 * overlay opens), the last control focused outside every overlay.
 */
function openerOf(focused: Element | null): HTMLElement | null {
    const closingOverlay = focused?.closest(
        `:is(${overlaySelector})[data-state="closed"]`,
    );
    if (
        focused instanceof HTMLElement &&
        focused !== document.body &&
        (!closingOverlay || closingOverlay.getAttribute('role') === 'menu')
    ) {
        const trigger = focused
            .closest('[role="menu"]')
            ?.getAttribute('aria-labelledby');
        return trigger ? document.getElementById(trigger) : focused;
    }
    return lastFocusOutsideOverlays?.isConnected
        ? lastFocusOutsideOverlays
        : null;
}

type Opener = {
    element: HTMLElement;
    /** The opener's accessible name and role: a re-rendered control of the same kind (More actions) stands in for it. */
    name: string | null;
    role: string;
    /** The region the opener sat in, or its id-less landmark replacement found again by heading text. */
    region: Element | null;
    regionHeading: string | null;
};

function nameOf(element: Element): string | null {
    return (
        element.getAttribute('aria-label') ??
        (element.textContent?.trim() || null)
    );
}

function roleOf(element: Element): string {
    return element.getAttribute('role') ?? element.tagName.toLowerCase();
}

function visible(element: Element): element is HTMLElement {
    return (
        element instanceof HTMLElement &&
        element.isConnected &&
        (element.checkVisibility?.() ?? true)
    );
}

function headingOf(region: Element): HTMLElement | null {
    return region.querySelector<HTMLElement>(
        'h1, h2, h3, [data-slot="card-title"], [data-slot="section-heading"]',
    );
}

function remember(element: HTMLElement): Opener {
    const region = element.closest(regionSelector);
    return {
        element,
        name: nameOf(element),
        role: roleOf(element),
        region,
        regionHeading: region
            ? (headingOf(region)?.textContent?.trim() ?? null)
            : null,
    };
}

/** A stand-in that receives focus programmatically without becoming a tab stop. */
function focusable(element: HTMLElement): HTMLElement {
    if (
        !element.matches('a[href], button, input, select, textarea, [tabindex]')
    )
        element.tabIndex = -1;
    return element;
}

/**
 * The opener itself while it is still on screen; otherwise a stable replacement, never BODY: a control with the same
 * name and role in the same region (the toolbar's More actions after a resize folded it), else that region's heading,
 * else the main landmark.
 */
function returnTarget(opener: Opener): HTMLElement | null {
    if (visible(opener.element)) return opener.element;
    const region =
        (opener.region?.isConnected ? opener.region : null) ??
        [...document.querySelectorAll(regionSelector)].find(
            (candidate) =>
                opener.regionHeading !== null &&
                headingOf(candidate)?.textContent?.trim() ===
                    opener.regionHeading,
        ) ??
        null;
    const scope = region ?? document.querySelector('main');
    if (scope) {
        const twin = [
            ...scope.querySelectorAll(
                'a[href], button, [role="button"], [role="combobox"], [tabindex]',
            ),
        ].find(
            (candidate) =>
                !candidate.closest(overlaySelector) &&
                roleOf(candidate) === opener.role &&
                nameOf(candidate) === opener.name &&
                visible(candidate),
        );
        if (twin instanceof HTMLElement) return twin;
        // The opener's action folded into its region's overflow menu (a card toolbar below 640 px): its trigger stands in.
        const overflowTrigger = region
            ? [...region.querySelectorAll('[aria-haspopup="menu"]')].find(
                  (candidate) => visible(candidate),
              )
            : undefined;
        if (overflowTrigger instanceof HTMLElement) return overflowTrigger;
        const heading = region ? headingOf(region) : null;
        if (heading && visible(heading)) return focusable(heading);
    }
    const main = document.querySelector('main');
    return main instanceof HTMLElement ? focusable(main) : null;
}

type AutoFocusHandler = (event: Event) => void;

/**
 * Overlays opened from state (no Radix Trigger) leave Radix nothing to refocus on close. Records the opener when the
 * overlay opens and returns focus to it (or a stable replacement, see returnTarget) when it closes, unless the caller's
 * own close handler already moved focus or the app is leaving the page (the new page owns focus).
 */
export function useReturnFocusToOpener(handlers: {
    onOpenAutoFocus?: AutoFocusHandler;
    onCloseAutoFocus?: AutoFocusHandler;
}): { onOpenAutoFocus: AutoFocusHandler; onCloseAutoFocus: AutoFocusHandler } {
    const opener = useRef<Opener | null>(null);
    const openedAt = useRef(0);
    return {
        onOpenAutoFocus: (event) => {
            const element = openerOf(document.activeElement);
            opener.current = element ? remember(element) : null;
            openedAt.current = departures;
            handlers.onOpenAutoFocus?.(event);
        },
        onCloseAutoFocus: (event) => {
            // Leaving the page: neither this hook nor a caller's custom restoration (the palette's Search) may pull focus
            // back; the new page owns it.
            if (leaving !== null || departures !== openedAt.current) {
                event.preventDefault();
                return;
            }
            handlers.onCloseAutoFocus?.(event);
            if (event.defaultPrevented || opener.current === null) return;
            const target = returnTarget(opener.current);
            if (target === null) return;
            event.preventDefault();
            target.focus();
        },
    };
}

import {
    createContext,
    useCallback,
    useContext,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type {
    FocusEvent,
    KeyboardEvent,
    ReactElement,
    ReactNode,
    RefObject,
} from 'react';
import { createPortal } from 'react-dom';
import {
    Tooltip,
    TooltipContent,
    TooltipPanel,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * Hover and focus descriptions use the shared Tooltip, never a native `title` (heptapod-ui-rules Tooltips).
 *
 * - Mouse: each hint opens its own tooltip on hover.
 * - Screen readers: a TooltipHint's value is also rendered as visually hidden text after its element, so it is read
 *   without opening anything; TruncatedText keeps its full text in the DOM (truncation is visual only).
 * - Keyboard inside a list row (ListRow, TableRow or RowHints): no new tab stops. When the row's link or button gets
 *   keyboard focus, one combined tooltip at that element lists the row's hints in column order ("Started: … · Duration: …").
 * - Keyboard outside rows, and in read-only rows that have no link or button to carry the combined tooltip: a hint whose
 *   element is not focusable itself (a lone <time>, a cut-off paragraph) becomes one tab stop with the shared focus ring;
 *   a link or button child opens its tooltip on focus as it is.
 *
 * The app root owns the one TooltipProvider; tests wrap renders in TooltipProvider.
 */

type HintEntry = {
    element: () => Element | null;
    label?: ReactNode;
    content: ReactNode;
    relevant: () => boolean;
};

type RowHintRegistry = {
    register: (entry: RefObject<HintEntry>) => () => void;
    /** While the row's combined keyboard tooltip is open, the hints in it stay closed. */
    active: boolean;
};

const RowHintContext = createContext<RowHintRegistry | null>(null);

/** Row elements that show the combined tooltip on keyboard focus; menu triggers keep their menu. */
const rowFocusTarget =
    'a[href], button:not([aria-haspopup]):not([aria-expanded])';
const interactive =
    'a[href], button, input, select, textarea, [tabindex], [role="button"], [role="link"]';

function overflows(element: Element): boolean {
    return (
        element.scrollWidth > element.clientWidth + 1 ||
        element.scrollHeight > element.clientHeight + 1
    );
}

const always = () => true;

/** One ResizeObserver for every standalone hint: a list page can hold hundreds of them. */
const resizeCallbacks = new Map<Element, () => void>();
let resizeObserver: ResizeObserver | null = null;

function observeResize(element: Element, callback: () => void) {
    if (typeof ResizeObserver === 'undefined') return undefined;
    resizeObserver ??= new ResizeObserver((entries) => {
        for (const entry of entries) resizeCallbacks.get(entry.target)?.();
    });
    resizeCallbacks.set(element, callback);
    resizeObserver.observe(element);
    return () => {
        resizeCallbacks.delete(element);
        resizeObserver?.unobserve(element);
    };
}

function cutOff(element: Element | null): boolean {
    return (
        element !== null &&
        [element, ...element.querySelectorAll('[data-truncate]')].some(
            overflows,
        )
    );
}

/** The row half of the hint system: put `context` on a provider, `handlers` on the row element and render `tooltip`. */
export function useRowHints() {
    const entries = useRef(new Set<RefObject<HintEntry>>());
    // Focus that follows a pointer press is a click, not keyboard navigation: the combined tooltip is keyboard-only.
    const pointerFocus = useRef(false);
    const [open, setOpen] = useState<{
        anchor: HTMLElement;
        items: HintEntry[];
    } | null>(null);
    const active = open !== null;
    const context = useMemo<RowHintRegistry>(
        () => ({
            register: (entry) => {
                entries.current.add(entry);
                return () => {
                    entries.current.delete(entry);
                };
            },
            active,
        }),
        [active],
    );
    const handlers = {
        onFocus: (event: FocusEvent<HTMLElement>) => {
            const target = event.target;
            if (pointerFocus.current || !target.matches(rowFocusTarget)) {
                return;
            }
            const items = [...entries.current]
                .map((entry) => entry.current)
                .filter((entry) => entry.relevant())
                .sort((a, b) => {
                    const left = a.element();
                    const right = b.element();
                    if (left === null || right === null) return 0;
                    return left.compareDocumentPosition(right) &
                        Node.DOCUMENT_POSITION_FOLLOWING
                        ? -1
                        : 1;
                });
            setOpen(items.length > 0 ? { anchor: target, items } : null);
        },
        onPointerDown: () => {
            pointerFocus.current = true;
        },
        onBlur: () => {
            pointerFocus.current = false;
            setOpen(null);
        },
        onKeyDown: (event: KeyboardEvent<HTMLElement>) => {
            if (event.key === 'Escape') setOpen(null);
        },
    };
    const tooltip =
        open === null ? null : (
            <RowHintTooltip
                anchor={open.anchor}
                items={open.items}
                onClose={() => setOpen(null)}
            />
        );
    return { context, handlers, tooltip };
}

export const RowHintProvider = RowHintContext.Provider;

/** For linked cards that are not ListRow/TableRow: the card (or its wrapper) becomes the hint row. */
export function RowHints({ children }: { children: ReactElement }) {
    const { context, handlers, tooltip } = useRowHints();
    return (
        <RowHintProvider value={context}>
            <div className="contents" data-row-hints-root {...handlers}>
                {children}
            </div>
            {tooltip}
        </RowHintProvider>
    );
}

function RowHintTooltip({
    anchor,
    items,
    onClose,
}: {
    anchor: HTMLElement;
    items: HintEntry[];
    onClose: () => void;
}) {
    const panel = useRef<HTMLDivElement>(null);
    const [position, setPosition] = useState<{
        top: number;
        left: number;
    } | null>(null);
    useLayoutEffect(() => {
        const box = anchor.getBoundingClientRect();
        const width = panel.current?.offsetWidth ?? 0;
        const height = panel.current?.offsetHeight ?? 0;
        const below = box.bottom + 8;
        setPosition({
            top:
                below + height > window.innerHeight - 8
                    ? Math.max(8, box.top - 8 - height)
                    : below,
            left: Math.min(
                Math.max(8, box.left),
                Math.max(8, window.innerWidth - width - 8),
            ),
        });
        window.addEventListener('scroll', onClose, true);
        window.addEventListener('resize', onClose);
        return () => {
            window.removeEventListener('scroll', onClose, true);
            window.removeEventListener('resize', onClose);
        };
    }, [anchor, onClose]);
    return createPortal(
        <TooltipPanel
            ref={panel}
            data-state="delayed-open"
            data-row-hints
            className="fixed origin-top-left"
            style={{
                top: position?.top ?? 0,
                left: position?.left ?? 0,
                visibility: position === null ? 'hidden' : undefined,
            }}
        >
            {items.map((item, index) => (
                <div key={index}>
                    {item.label !== undefined && (
                        <span className="text-tooltip-muted">
                            {item.label}:{' '}
                        </span>
                    )}
                    {item.content}
                </div>
            ))}
        </TooltipPanel>,
        document.body,
    );
}

/**
 * Shared hint state: registers in an enclosing row, makes a non-focusable standalone element one tab stop, and keeps the
 * hint's own tooltip closed while the row's combined tooltip is open.
 */
function useHint({
    label,
    content,
    relevant,
    standaloneFocus,
}: {
    label?: ReactNode;
    content: ReactNode;
    relevant: (element: Element | null) => boolean;
    standaloneFocus: (element: Element) => boolean;
}) {
    const row = useContext(RowHintContext);
    const trigger = useRef<Element | null>(null);
    const entry = useRef<HintEntry>({
        element: () => trigger.current,
        label,
        content,
        relevant: () => relevant(trigger.current),
    });
    entry.current = {
        element: () => trigger.current,
        label,
        content,
        relevant: () => relevant(trigger.current),
    };
    const register = row?.register;
    useLayoutEffect(() => register?.(entry), [register]);
    // Standalone (no row, or a read-only row without a link or button to carry the combined tooltip): a non-focusable
    // element becomes one tab stop while it has something to show; cut-off state follows resizes.
    const inRow = row !== null;
    useLayoutEffect(() => {
        const element = trigger.current;
        const rowFocus = element
            ?.closest(
                '[data-slot="list-row"], [data-slot="table-row"], [data-row-hints-root]',
            )
            ?.querySelector(rowFocusTarget);
        if (
            (inRow && rowFocus) ||
            !(element instanceof HTMLElement) ||
            element.matches(interactive) ||
            element.closest('a[href], button, label') !== null
        ) {
            return;
        }
        const update = () => {
            const focusable = standaloneFocus(element);
            element.toggleAttribute('data-hint-focus', focusable);
            if (focusable) {
                element.tabIndex = 0;
            } else {
                element.removeAttribute('tabindex');
            }
        };
        update();
        return observeResize(element, update);
    }, [inRow, standaloneFocus, content]);
    const [own, setOwn] = useState(false);
    return {
        triggerRef: useCallback((node: Element | null) => {
            trigger.current = node;
        }, []),
        trigger,
        open: own && !(row?.active ?? false),
        setOwn,
    };
}

/**
 * Always shows `content` (an exact timestamp, a URL behind an icon); renders the child unchanged without content. `label`
 * names the value in a row's combined keyboard tooltip ("Started").
 */
export function TooltipHint({
    content,
    label,
    children,
}: {
    content: ReactNode;
    label?: ReactNode;
    children: ReactElement;
}) {
    const empty = content === undefined || content === null || content === '';
    const hint = useHint({
        label,
        content,
        relevant: () => !empty,
        standaloneFocus: always,
    });
    if (empty) {
        return children;
    }
    return (
        <>
            <Tooltip open={hint.open} onOpenChange={hint.setOwn}>
                <TooltipTrigger asChild ref={hint.triggerRef}>
                    {children}
                </TooltipTrigger>
                <TooltipContent>{content}</TooltipContent>
            </Tooltip>
            <span className="sr-only"> ({content})</span>
        </>
    );
}

/**
 * Shows the full text only while it is cut off: the child (`truncate` or `line-clamp-*`) or a descendant marked
 * `data-truncate` (the text span inside a link) overflows its box. Fitting text never gets a tooltip or a tab stop.
 */
export function TruncatedText({
    content,
    label,
    children,
}: {
    content: ReactNode;
    label?: ReactNode;
    children: ReactElement;
}) {
    const hint = useHint({
        label,
        content,
        relevant: cutOff,
        standaloneFocus: cutOff,
    });
    return (
        <Tooltip
            open={hint.open}
            onOpenChange={(next) =>
                hint.setOwn(next && cutOff(hint.trigger.current))
            }
        >
            <TooltipTrigger asChild ref={hint.triggerRef}>
                {children}
            </TooltipTrigger>
            <TooltipContent>{content}</TooltipContent>
        </Tooltip>
    );
}

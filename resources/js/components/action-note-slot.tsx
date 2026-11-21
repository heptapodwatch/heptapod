import { Popover } from 'radix-ui';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { focusRingClass } from '@/lib/interaction';
import { cn } from '@/lib/utils';

const REFUSAL_PRIORITY_MS = 5000;

export type ActionNoteDescriptor = {
    /** Referenced by the action button's aria-describedby while a note is present. */
    id: string;
    /** Standing note (CooldownReason, RunCheckReason, or persistent status). */
    content?: ReactNode;
    /** Fresh refusal message(s). Shows briefly ahead of standing content, then yields so countdowns stay visible. */
    refusal?: string | readonly string[] | null;
    /** Changes to this key (e.g. run phase or cooldown change) yield transient refusal priority to standing content. */
    standingKey?: string | number | null;
};

/** Whether an ActionNoteDescriptor currently holds any visible note (for wiring aria-describedby). */
export function hasActionNote(
    note: ActionNoteDescriptor | null | undefined,
): boolean {
    if (!note) return false;
    const hasContent =
        note.content !== undefined &&
        note.content !== null &&
        note.content !== false;
    const hasRefusal = Array.isArray(note.refusal)
        ? note.refusal.some(Boolean)
        : Boolean(note.refusal);
    return hasContent || hasRefusal;
}

function overflows(element: Element): boolean {
    return (
        element.scrollWidth > element.clientWidth + 1 ||
        element.scrollHeight > element.clientHeight + 1
    );
}

function isCutOff(element: Element | null): boolean {
    return (
        element !== null &&
        [element, ...element.querySelectorAll('[data-truncate]')].some(
            overflows,
        )
    );
}

/**
 * Reserved single-line slot for an inline action note (CooldownReason, RunCheckReason, or action-level InputError /
 * status). Keeps a fixed 20 px height whether empty or populated so showing, changing, or hiding a note never moves or
 * resizes surrounding controls. Clipped or multi-item notes open their full text on hover, keyboard focus, and touch
 * tap through one shared disclosure while keeping full text in the DOM for the action button's aria-describedby.
 */
export function ActionNoteSlot({
    id,
    align = 'start',
    className,
    refusal,
    standingKey,
    children,
}: {
    /** Referenced by the action button's aria-describedby while a note is present. */
    id?: string;
    /** Right-align under right-aligned desktop header actions; left-align everywhere else. */
    align?: 'start' | 'end-sm' | 'end-md';
    className?: string;
    /** Fresh refusal message(s); takes brief priority over standing children, then yields without hiding countdowns. */
    refusal?: string | readonly string[] | null;
    /** Changes to this key yield transient refusal priority immediately to standing children. */
    standingKey?: string | number | null;
    children?: ReactNode;
}) {
    const { t } = useTranslation();
    const refusals = useMemo(
        () =>
            (Array.isArray(refusal)
                ? refusal
                : refusal
                  ? [refusal]
                  : []
            ).filter((item): item is string => Boolean(item)),
        [refusal],
    );
    const refusalSignature = refusals.join('\0');
    const hasStanding =
        children !== undefined && children !== null && children !== false;
    const hasRefusal = refusals.length > 0;
    const empty = !hasStanding && !hasRefusal;

    const prevRefusal = useRef(refusalSignature);
    const prevStanding = useRef(standingKey);
    const [preferRefusal, setPreferRefusal] = useState(hasRefusal);

    useEffect(() => {
        const refusalChanged = prevRefusal.current !== refusalSignature;
        const standingChanged = prevStanding.current !== standingKey;
        prevRefusal.current = refusalSignature;
        prevStanding.current = standingKey;

        if (!hasRefusal) {
            setPreferRefusal(false);
            return;
        }
        if (refusalChanged) {
            setPreferRefusal(true);
        } else if (standingChanged && hasStanding) {
            setPreferRefusal(false);
        }
    }, [refusalSignature, standingKey, hasRefusal, hasStanding]);

    useEffect(() => {
        if (!preferRefusal || !hasRefusal || !hasStanding) return;
        const timer = window.setTimeout(
            () => setPreferRefusal(false),
            REFUSAL_PRIORITY_MS,
        );
        return () => window.clearTimeout(timer);
    }, [preferRefusal, hasRefusal, hasStanding, refusalSignature]);

    const showingRefusal = hasRefusal && (preferRefusal || !hasStanding);
    const visibleLine = showingRefusal ? (
        <InputError message={refusals[0]} />
    ) : hasStanding ? (
        children
    ) : null;

    const contentElement = useRef<HTMLDivElement | null>(null);
    const popoverContent = useRef<HTMLDivElement | null>(null);
    const pointerFocus = useRef(false);
    const [clipped, setClipped] = useState(false);
    const [open, setOpen] = useState(false);
    const [pinned, setPinned] = useState(false);

    const hasExtraDetails = refusals.length > 1 || (hasRefusal && hasStanding);
    const interactive = !empty && (clipped || hasExtraDetails);

    const measure = useCallback(() => {
        setClipped(isCutOff(contentElement.current));
    }, []);

    useLayoutEffect(() => {
        const node = contentElement.current;
        if (empty || node === null) {
            setClipped(false);
            return;
        }
        measure();
        if (typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(measure);
        observer.observe(node);
        return () => observer.disconnect();
    }, [empty, measure, refusalSignature, showingRefusal, children]);

    useEffect(() => {
        if (!interactive) {
            setOpen(false);
            setPinned(false);
        }
    }, [interactive]);

    const closeDisclosure = useCallback(
        (acknowledged: boolean) => {
            setPinned(false);
            setOpen(false);
            if (acknowledged && hasStanding && preferRefusal) {
                setPreferRefusal(false);
            }
        },
        [hasStanding, preferRefusal],
    );

    const togglePinned = useCallback(() => {
        if (pinned) {
            closeDisclosure(true);
        } else {
            setPinned(true);
            setOpen(true);
        }
    }, [pinned, closeDisclosure]);

    // Keep all additional feedback in the DOM under `id` so aria-describedby never drops extra refusals or standing info.
    const srSupplementary = showingRefusal ? (
        <>
            {refusals.slice(1).map((message, index) => (
                <span key={index} className="sr-only">
                    {' '}
                    {t(message)}
                </span>
            ))}
            {hasStanding && <span className="sr-only"> {children}</span>}
        </>
    ) : hasRefusal ? (
        <>
            {refusals.map((message, index) => (
                <span key={index} className="sr-only">
                    {' '}
                    {t(message)}
                </span>
            ))}
        </>
    ) : null;

    return (
        <div
            id={id}
            data-slot="action-note-slot"
            className={cn(
                'flex h-5 min-w-0 items-center text-xs text-muted-foreground',
                align === 'end-sm' && 'sm:justify-end',
                align === 'end-md' && 'md:justify-end',
                className,
            )}
        >
            {!empty && (
                <Popover.Root
                    modal={false}
                    open={interactive && open}
                    onOpenChange={(next) => {
                        if (!next) {
                            closeDisclosure(pinned);
                        }
                    }}
                >
                    <Popover.Trigger asChild>
                        <div
                            ref={contentElement}
                            role={interactive ? 'button' : undefined}
                            tabIndex={interactive ? 0 : undefined}
                            aria-expanded={interactive ? open : undefined}
                            className={cn(
                                'min-w-0 truncate [&_p]:inline',
                                interactive &&
                                    'cursor-pointer rounded-xs underline decoration-dotted underline-offset-2',
                                interactive && focusRingClass,
                            )}
                            onPointerEnter={(event) => {
                                if (
                                    interactive &&
                                    event.pointerType === 'mouse' &&
                                    !pinned
                                ) {
                                    setOpen(true);
                                }
                            }}
                            onPointerLeave={(event) => {
                                if (
                                    interactive &&
                                    event.pointerType === 'mouse' &&
                                    !pinned
                                ) {
                                    setOpen(false);
                                }
                            }}
                            onPointerDown={() => {
                                if (interactive) {
                                    pointerFocus.current = true;
                                }
                            }}
                            onFocus={() => {
                                if (
                                    interactive &&
                                    !pointerFocus.current &&
                                    !pinned
                                ) {
                                    setOpen(true);
                                }
                                pointerFocus.current = false;
                            }}
                            onBlur={(event) => {
                                if (
                                    interactive &&
                                    !pinned &&
                                    !popoverContent.current?.contains(
                                        event.relatedTarget as Node | null,
                                    )
                                ) {
                                    setOpen(false);
                                }
                            }}
                            onClick={(event) => {
                                if (!interactive) return;
                                event.preventDefault();
                                togglePinned();
                            }}
                            onKeyDown={(event) => {
                                if (!interactive) return;
                                if (
                                    event.key === 'Enter' ||
                                    event.key === ' '
                                ) {
                                    event.preventDefault();
                                    togglePinned();
                                }
                            }}
                        >
                            {visibleLine}
                        </div>
                    </Popover.Trigger>
                    {interactive && (
                        <Popover.Portal>
                            <Popover.Content
                                ref={popoverContent}
                                side="bottom"
                                align={align === 'start' ? 'start' : 'end'}
                                sideOffset={8}
                                collisionPadding={8}
                                onOpenAutoFocus={(event) =>
                                    event.preventDefault()
                                }
                                className="tooltip-panel motion-popover z-50 max-w-[min(28rem,calc(100vw-1rem))] space-y-1 rounded-[6px] border border-tooltip-border bg-tooltip-surface px-[9px] py-[6px] font-mono text-xs leading-[17px] text-tooltip-subject shadow-overlay outline-none"
                            >
                                {hasRefusal &&
                                    refusals.map((message, index) => (
                                        <InputError
                                            key={index}
                                            role={undefined}
                                            message={message}
                                        />
                                    ))}
                                {hasStanding && <div>{children}</div>}
                            </Popover.Content>
                        </Popover.Portal>
                    )}
                </Popover.Root>
            )}
            {srSupplementary}
        </div>
    );
}

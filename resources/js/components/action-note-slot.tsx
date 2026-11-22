import { Popover } from 'radix-ui';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { tooltipPanelClass } from '@/components/ui/tooltip';
import { focusRingClass } from '@/lib/interaction';
import { cn } from '@/lib/utils';

export type ActionNoteDescriptor = {
    /** Referenced by the action button's aria-describedby while a note is present. */
    id: string;
    /** Single-line note rendered inside the reserved slot (CooldownReason, RunCheckReason, or status). */
    content?: ReactNode;
};

/** Whether an ActionNoteDescriptor currently holds a visible note (for wiring aria-describedby). */
export function hasActionNote(
    note: ActionNoteDescriptor | null | undefined,
): boolean {
    return Boolean(
        note &&
        note.content !== undefined &&
        note.content !== null &&
        note.content !== false,
    );
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
 * resizes surrounding controls. Clipped notes open their full text on hover, keyboard focus, and touch tap through one
 * shared Popover disclosure while keeping full text in the DOM for the action button's aria-describedby.
 */
export function ActionNoteSlot({
    id,
    align = 'start',
    className,
    children,
}: {
    /** Referenced by the action button's aria-describedby while a note is present. */
    id?: string;
    /** Right-align under right-aligned desktop header actions; left-align everywhere else. */
    align?: 'start' | 'end-sm' | 'end-md';
    className?: string;
    children?: ReactNode;
}) {
    const empty =
        children === undefined || children === null || children === false;
    const contentElement = useRef<HTMLDivElement | null>(null);
    const popoverContent = useRef<HTMLDivElement | null>(null);
    const pointerFocus = useRef(false);
    const [clipped, setClipped] = useState(false);
    const [open, setOpen] = useState(false);
    const [pinned, setPinned] = useState(false);

    const interactive = !empty && clipped;

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
    }, [empty, measure, children]);

    useEffect(() => {
        if (!interactive) {
            setOpen(false);
            setPinned(false);
        }
    }, [interactive]);

    const togglePinned = useCallback(() => {
        setPinned((current) => {
            const next = !current;
            setOpen(next);
            return next;
        });
    }, []);

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
                            setPinned(false);
                            setOpen(false);
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
                            {children}
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
                                className={cn(
                                    tooltipPanelClass,
                                    'motion-pop outline-none',
                                )}
                            >
                                {children}
                            </Popover.Content>
                        </Popover.Portal>
                    )}
                </Popover.Root>
            )}
        </div>
    );
}

import type { ReactNode } from 'react';
import { TruncatedText } from '@/components/truncated-text';
import { cn } from '@/lib/utils';

/**
 * Reserved single-line slot for an inline action note (CooldownReason, RunCheckReason, or action-level InputError /
 * status). Keeps a fixed 20 px height whether empty or populated so showing, changing, or hiding a note never moves or
 * resizes surrounding controls. Long notes truncate to one line through TruncatedText and keep their full text in the
 * DOM for the action button's aria-describedby.
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
                <TruncatedText content={children}>
                    <div className="min-w-0 truncate [&_p]:inline">
                        {children}
                    </div>
                </TruncatedText>
            )}
        </div>
    );
}

import { Check, Copy } from 'lucide-react';
import { useId, useState, type ComponentProps, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ActionNoteSlot } from '@/components/action-note-slot';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';

/**
 * The one copy control (heptapod-ui-rules, Interaction states): "Copy" / "Copied", or icon-only with `iconLabel`.
 * "Copied" is announced and resets after 2 s. A refused clipboard is noted beneath a standalone button; beside its
 * value (`inline`, always for icon-only) the note would open a gap in every row, so it is a toast.
 */
export function CopyButton({
    value,
    iconLabel,
    inline = iconLabel !== undefined,
    onRefused,
    variant = 'outline',
    size,
    className,
    disabled,
    children,
}: {
    /** The text, or a function that fetches it on click. */
    value: string | (() => Promise<string>);
    /** Icon-only, with this accessible name (what is copied: "Copy IP address"). */
    iconLabel?: string;
    /** With `iconLabel`: the value shown inside the button before the icon, so the whole value is the target. */
    children?: ReactNode;
    /** Sits beside the value it copies (a command, a URL row): no reserved note line. */
    inline?: boolean;
    /** Replaces the refused note, for a value the page cannot show to select by hand (the export's manual dialog). */
    onRefused?: (text: string | Promise<string>) => void;
} & Pick<
    ComponentProps<typeof Button>,
    'variant' | 'size' | 'className' | 'disabled'
>) {
    const { t } = useTranslation();
    const [copied, copy] = useClipboard();
    const [refused, setRefused] = useState(false);
    const noteId = useId();
    const showsNote = !inline && onRefused === undefined;
    const Icon = iconLabel !== undefined && copied ? Check : Copy;
    const button = (
        <Button
            type="button"
            variant={variant}
            size={size ?? (iconLabel === undefined ? 'default' : 'icon')}
            className={className}
            disabled={disabled}
            aria-label={children === undefined ? iconLabel : undefined}
            aria-describedby={showsNote && refused ? noteId : undefined}
            onClick={async () => {
                const text = typeof value === 'string' ? value : value();
                const ok = await copy(text);
                setRefused(!ok);
                if (ok) return;
                if (onRefused !== undefined) {
                    onRefused(text);
                } else if (!showsNote) {
                    toast.warning(t('errors.clipboard_unavailable'));
                }
            }}
        >
            {children !== undefined && (
                <>
                    <span className="sr-only">{iconLabel} </span>
                    {children}
                </>
            )}
            <Icon aria-hidden />
            {iconLabel === undefined && t(copied ? 'Copied' : 'Copy')}
        </Button>
    );
    // Outside the button, so its name stays "Copy" / "Copied" while screen readers still hear the confirmation.
    const announcement = (
        <span role="status" className="sr-only">
            {copied ? t('Copied') : ''}
        </span>
    );
    if (!showsNote) {
        return (
            <>
                {button}
                {announcement}
            </>
        );
    }
    return (
        <div className="space-y-1.5">
            {button}
            {announcement}
            <ActionNoteSlot id={noteId}>
                {refused && (
                    <InputError message={t('errors.clipboard_unavailable')} />
                )}
            </ActionNoteSlot>
        </div>
    );
}

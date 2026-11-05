import { Link } from '@inertiajs/react';
import type {
    ComponentProps,
    MouseEventHandler,
    ReactElement,
    ReactNode,
} from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { TooltipHint, TruncatedText } from '@/components/truncated-text';

export type TextLinkVariant = 'primary' | 'secondary';

/**
 * Both variants are underlined at rest: foreground vs muted-foreground is about 2:1, so colour alone fails WCAG 1.4.1.
 * Hover only removes the underline (the colour stays); active restores it at full strength. The underline sits on the inner text span, so an
 * icon in the same click target is never underlined.
 */
export const linkClass: Record<TextLinkVariant, string> = {
    primary:
        'group/link rounded-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
    secondary:
        'group/link rounded-sm text-xs text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
};

export const linkTextClass: Record<TextLinkVariant, string> = {
    primary:
        'underline decoration-current/40 underline-offset-4 group-hover/link:no-underline group-active/link:underline group-active/link:decoration-current',
    secondary:
        'underline decoration-current/50 underline-offset-3 group-hover/link:no-underline group-active/link:underline group-active/link:decoration-current',
};

/**
 * The tall line height keeps each line's underline (offset below the baseline) inside its own line box, so a
 * line-clamp or overflow-hidden ancestor does not clip the underline of the last visible line.
 */
const urlClass = 'font-mono leading-[1.75] [overflow-wrap:anywhere]';

/** Single line with an ellipsis (fixed-height slots); the underline stays on the one visible line. */
const truncateTextClass = 'block min-w-0 truncate';

/** The tooltip for truncated link text: the caller's title, else the text itself. */
function truncateTitle(title: string | undefined, children: ReactNode): string {
    if (title !== undefined) return title;
    if (typeof children === 'string') return children;
    throw new Error('A truncated link needs a title or plain-text children.');
}

/**
 * The shared tooltip for a link: a truncated link shows the full text only while it is cut off; any other `title` is an
 * always-on hint. Never a native title attribute.
 */
function withTitle(
    element: ReactElement,
    truncate: boolean,
    title: string | undefined,
    children: ReactNode,
): ReactElement {
    if (truncate) {
        return (
            <TruncatedText content={truncateTitle(title, children)}>
                {element}
            </TruncatedText>
        );
    }
    return <TooltipHint content={title}>{element}</TooltipHint>;
}

type Common = {
    variant?: TextLinkVariant;
    /** Mono font and wrapping anywhere, for URLs as link text. */
    url?: boolean;
    /** One line with an ellipsis; the full text (or title) shows in the shared tooltip while it is cut off. */
    truncate?: boolean;
    icon?: ReactNode;
    className?: string;
    children?: ReactNode;
};

type InternalProps = Common &
    Omit<ComponentProps<typeof Link>, 'className' | 'children'> & {
        external?: false;
        document?: false;
    };

/** A same-site URL that must load as a document, not an Inertia visit (downloads, in-page anchors). */
type DocumentProps = Common & {
    document: true;
    external?: false;
    href: string;
    title?: string;
};

type ExternalProps = Common & {
    external: true;
    document?: false;
    /** Untrusted values are allowed: anything but http(s) renders as plain text. */
    href: string;
    title?: string;
};

/** Only recorded HTTP(S) URLs may become links. */
export function safeHttpUrl(value: string): URL | null {
    if (!URL.canParse(value)) return null;
    const url = new URL(value);
    return url.protocol === 'http:' || url.protocol === 'https:' ? url : null;
}

export function TextLink(props: InternalProps | ExternalProps | DocumentProps) {
    const { t } = useTranslation();
    const {
        variant = 'primary',
        url = false,
        truncate = false,
        icon,
        className,
        children,
        ...rest
    } = props;
    const title = rest.title;
    const text = (
        <span
            data-link-text
            data-truncate={truncate || undefined}
            className={cn(
                linkTextClass[variant],
                truncate && truncateTextClass,
            )}
        >
            {children}
        </span>
    );
    const outer = cn(
        linkClass[variant],
        url && urlClass,
        icon && 'inline-flex items-center gap-1.5',
        className,
    );
    if (rest.external) {
        const { href } = rest;
        const safe = safeHttpUrl(href);
        if (!safe) {
            return withTitle(
                <span className={cn(url && urlClass, className)}>
                    {children ?? href}
                </span>,
                false,
                title,
                children,
            );
        }
        return withTitle(
            <a
                href={safe.href}
                target="_blank"
                rel="noopener noreferrer"
                className={outer}
            >
                {icon}
                {text}
                <span className="sr-only"> {t('(opens in a new tab)')}</span>
            </a>,
            truncate,
            title,
            children,
        );
    }
    if (rest.document) {
        return withTitle(
            <a href={rest.href} className={outer}>
                {icon}
                {text}
            </a>,
            truncate,
            title,
            children,
        );
    }
    const {
        external: _external,
        document: _document,
        title: _title,
        ...linkProps
    } = rest;
    return withTitle(
        <Link {...linkProps} className={outer}>
            {icon}
            {text}
        </Link>,
        truncate,
        title,
        children,
    );
}

/** An in-place action (not navigation) that shares the link look. */
export function TextLinkButton({
    variant = 'secondary',
    truncate = false,
    className,
    children,
    onClick,
    title,
}: {
    variant?: TextLinkVariant;
    /** One line with an ellipsis; the full text (or title) shows in the shared tooltip while it is cut off. */
    truncate?: boolean;
    className?: string;
    children: ReactNode;
    onClick: MouseEventHandler<HTMLButtonElement>;
    title?: string;
}) {
    return withTitle(
        <button
            type="button"
            onClick={onClick}
            className={cn(linkClass[variant], 'cursor-pointer', className)}
        >
            <span
                data-link-text
                data-truncate={truncate || undefined}
                className={cn(
                    linkTextClass[variant],
                    truncate && truncateTextClass,
                )}
            >
                {children}
            </span>
        </button>,
        truncate,
        title,
        children,
    );
}

export default TextLink;

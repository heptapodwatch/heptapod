import { MoreHorizontal } from 'lucide-react';
import {
    createContext,
    useContext,
    useEffect,
    useRef,
    useState,
    type ComponentProps,
    type ReactNode,
} from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';

const InHeaderMenu = createContext(false);

export function PageHeaderAction({
    className,
    ...props
}: ComponentProps<typeof Button>) {
    const inMenu = useContext(InHeaderMenu);
    if (!inMenu) return <Button className={className} {...props} />;

    return (
        <DropdownMenuItem
            asChild
            disabled={props.disabled}
            onSelect={(event) => event.preventDefault()}
        >
            <Button
                {...props}
                variant="ghost"
                className={cn(
                    'w-full justify-start rounded-sm border-0 font-normal',
                    className,
                )}
            />
        </DropdownMenuItem>
    );
}

export function PageHeaderActions({
    primary,
    secondary,
    fullWidthOnMobile = false,
    overflowSecondary = false,
    secondaryOpen,
    onSecondaryOpenChange,
    secondaryActionCount,
}: {
    primary?: ReactNode;
    secondary?: ReactNode;
    fullWidthOnMobile?: boolean;
    /**
     * true: secondary actions overflow at every width, but a single secondary stays a button.
     * 'always': every secondary overflows, so the header shows exactly one primary plus More actions.
     */
    overflowSecondary?: boolean | 'always';
    secondaryOpen?: boolean;
    onSecondaryOpenChange?: (open: boolean) => void;
    secondaryActionCount?: number;
}) {
    const isMobile = useIsMobile();
    const [localOpen, setLocalOpen] = useState(false);
    const open = secondaryOpen ?? localOpen;
    const setOpen = onSecondaryOpenChange ?? setLocalOpen;
    return (
        <div
            className={cn(
                'flex shrink-0 items-center gap-2 md:ml-auto md:max-w-[60%] md:min-w-0 md:flex-wrap md:justify-end',
                fullWidthOnMobile && 'w-full md:w-auto',
            )}
        >
            {overflowSecondary === 'always' ||
            ((isMobile || overflowSecondary) &&
                (secondaryActionCount === undefined ||
                    secondaryActionCount > 1)) ? (
                <>
                    {fullWidthOnMobile ? (
                        <div className="flex-1">{primary}</div>
                    ) : (
                        primary
                    )}
                    {secondary && (
                        <ActionsOverflowMenu open={open} onOpenChange={setOpen}>
                            {secondary}
                        </ActionsOverflowMenu>
                    )}
                </>
            ) : (
                <>
                    {secondary}
                    {primary}
                </>
            )}
        </div>
    );
}

/**
 * The one "More actions" overflow: a `⋯` button whose menu renders PageHeaderAction children as menu items. Shared by
 * page headers and card toolbars (CardToolbarActions). After its first open the menu stays mounted so action dialogs and
 * errors survive closing.
 */
export function ActionsOverflowMenu({
    open,
    onOpenChange,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const trigger = useRef<HTMLButtonElement>(null);
    const content = useRef<HTMLDivElement>(null);
    // Mounted on first open, then retained. A closed menu content is still a Radix dismissable layer; mounted before
    // its first open (a toolbar folding at a breakpoint while a dialog is open) it would sit above that dialog and take
    // its Escape.
    const [retained, setRetained] = useState(open);
    if (open && !retained) setRetained(true);
    // Retained menus do not rerun Radix's mount-time autofocus when reopened.
    useEffect(() => {
        if (open) content.current?.focus();
    }, [open]);
    return (
        // A retained, closed modal menu would hide the page from assistive technology.
        <DropdownMenu modal={false} open={open} onOpenChange={onOpenChange}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label={t('More actions')}
                    ref={trigger}
                >
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            {/* Keep action dialogs and async errors mounted after the menu closes. */}
            <DropdownMenuContent
                forceMount={retained || undefined}
                ref={content}
                align="end"
                className="w-64 data-[state=closed]:hidden"
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        onOpenChange(false);
                        trigger.current?.focus();
                    }
                }}
                onInteractOutside={(event) => {
                    const target = event.detail.originalEvent.target;
                    if (
                        !open ||
                        (target instanceof Node &&
                            trigger.current?.contains(target))
                    ) {
                        event.preventDefault();
                    }
                }}
            >
                <InHeaderMenu value={true}>{children}</InHeaderMenu>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Whether a PageHeaderAction renders inside the overflow menu (for supporting text that only fits beside buttons). */
export function useInActionsMenu(): boolean {
    return useContext(InHeaderMenu);
}

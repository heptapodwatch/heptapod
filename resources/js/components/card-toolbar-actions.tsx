import { useState, type ReactNode } from 'react';
import { ActionsOverflowMenu } from '@/components/page-header-actions';
import { useIsBelowSm } from '@/hooks/use-mobile';

/**
 * A card header's action row (heptapod-ui-rules Cards). At 640 px and wider every action is a plain button; below,
 * they move into the shared "More actions" menu so the card title keeps its width. Actions are PageHeaderAction
 * elements; anything that must stay mounted when the menu closes (a fallback dialog) goes into `outside`.
 */
export function CardToolbarActions({
    children,
    outside,
    primary,
}: {
    children: ReactNode;
    outside?: ReactNode;
    /** Keep one primary visible and put the remaining actions in More at every width. */
    primary?: ReactNode;
}) {
    const narrow = useIsBelowSm();
    const [open, setOpen] = useState(false);
    return (
        <>
            {primary ? (
                <div className="flex items-center gap-2">
                    {primary}
                    {children && (
                        <ActionsOverflowMenu open={open} onOpenChange={setOpen}>
                            {children}
                        </ActionsOverflowMenu>
                    )}
                </div>
            ) : narrow ? (
                <ActionsOverflowMenu open={open} onOpenChange={setOpen}>
                    {children}
                </ActionsOverflowMenu>
            ) : (
                <div className="flex flex-wrap items-center justify-end gap-2">
                    {children}
                </div>
            )}
            {outside}
        </>
    );
}

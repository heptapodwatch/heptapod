import { MoreHorizontal } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type RowAction = {
    label: string;
    onSelect: () => void;
    disabled?: boolean;
    /** Irreversible actions (delete, revoke) render red; their confirmation dialog is destructive too. */
    destructive?: boolean;
    /** Keeps the existing data-test hooks that browser tests click. */
    testId?: string;
};

/**
 * The trailing row-action control (heptapod-ui-rules Lists): one action is a plain ghost button, two or more collapse
 * into one `⋯` menu at the row end. `label` names the row for the menu trigger ("More actions for Priya Raman").
 */
export function RowActions({
    label,
    actions,
}: {
    label: string;
    actions: RowAction[];
}) {
    const { t } = useTranslation();
    if (actions.length === 0) return null;
    const [only] = actions;
    if (actions.length === 1 && only !== undefined)
        return (
            <Button
                type="button"
                size="sm"
                variant="ghost"
                data-test={only.testId}
                disabled={only.disabled}
                onClick={only.onSelect}
            >
                {only.label}
            </Button>
        );
    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label={t('More actions for :name', { name: label })}
                >
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
                {actions.map((action) => (
                    <DropdownMenuItem
                        key={action.label}
                        data-test={action.testId}
                        disabled={action.disabled}
                        variant={action.destructive ? 'destructive' : 'default'}
                        onSelect={action.onSelect}
                    >
                        {action.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

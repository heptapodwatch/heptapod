import { ChevronDown, SlidersHorizontal, X } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { SelectTrigger } from '@/components/ui/select';
import { cn } from '@/lib/utils';

/**
 * The list toolbar. It owns the screen's one Clear filters, shown while any filter (search included) differs from its
 * default; `activeCount` counts only the active controls hidden in the mobile collapse.
 */
export function FilterBar({
    search,
    children,
    segmented,
    actions,
    status,
    onSubmit,
    activeCount,
    filtersActive,
    onClearFilters,
}: {
    search?: ReactNode;
    children?: ReactNode;
    segmented?: ReactNode;
    actions?: ReactNode;
    status?: ReactNode;
    onSubmit?: ComponentProps<'form'>['onSubmit'];
    activeCount: number;
    filtersActive: boolean;
    onClearFilters: () => void;
}) {
    const { t } = useTranslation();
    // Clear filters and the pending-changes prompt stay outside the mobile collapse, beside the Filters button.
    const outside = filtersActive || Boolean(status);
    const layout =
        'flex min-w-0 flex-col flex-wrap gap-2 md:flex-row md:items-center';
    const controls = (
        <>
            {search && (
                <div className="w-full md:max-w-xs md:min-w-64 md:flex-1 md:basis-64">
                    {search}
                </div>
            )}
            <div
                className={
                    outside
                        ? 'flex flex-wrap items-center gap-2 md:contents'
                        : 'contents'
                }
            >
                <Collapsible
                    className={outside ? 'contents' : 'min-w-0 md:contents'}
                >
                    <CollapsibleTrigger asChild>
                        <Button variant="outline" className="group md:hidden">
                            <SlidersHorizontal aria-hidden="true" />
                            {activeCount > 0
                                ? t('Filter · :count', { count: activeCount })
                                : t('Filters')}
                            <ChevronDown
                                aria-hidden="true"
                                className="group-data-[state=open]:rotate-180"
                            />
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent
                        forceMount
                        className={`${outside ? 'order-3 w-full md:order-none ' : ''}pt-2 data-[state=closed]:hidden md:contents md:pt-0 md:data-[state=closed]:contents`}
                    >
                        <div className={`${layout} md:contents`}>
                            {children}
                            {segmented && (
                                <div className="min-w-0 md:shrink-0">
                                    {segmented}
                                </div>
                            )}
                            {actions && (
                                <div className="flex min-w-0 flex-wrap items-center gap-2">
                                    {actions}
                                </div>
                            )}
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                {filtersActive && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="order-2 md:order-none"
                        onClick={onClearFilters}
                    >
                        {t('Clear filters')}
                    </Button>
                )}
                {status && (
                    <div className="order-2 flex min-w-0 items-center gap-2 md:order-none">
                        {status}
                    </div>
                )}
            </div>
        </>
    );
    return onSubmit ? (
        <form noValidate onSubmit={onSubmit} className={layout}>
            {controls}
        </form>
    ) : (
        <div className={layout}>{controls}</div>
    );
}

/** Trigger look of a filter control: muted at its default; foreground on the filled secondary surface while it filters. */
function filterTriggerClass(active: boolean) {
    return cn(
        'w-full md:w-40',
        active
            ? 'border-border-strong bg-secondary pr-10 text-foreground sm:pr-8 [&>svg:last-child]:hidden'
            : 'text-muted-foreground',
    );
}

/** The × inside an active filter control: its own button, so it resets only that filter and never opens the menu. */
function FilterClearButton({
    label,
    onClear,
}: {
    label: string;
    onClear: () => void;
}) {
    const { t } = useTranslation();
    return (
        <button
            type="button"
            aria-label={t('Clear :label filter', { label })}
            onClick={onClear}
            className="absolute inset-y-0 right-0 flex w-10 cursor-pointer items-center justify-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-8"
        >
            <X aria-hidden="true" className="size-4" />
        </button>
    );
}

/**
 * Toolbar filter over growable data: FilterSelectTrigger look, searchable Combobox inside. `onClear` is set only while the
 * filter differs from its default; the control then shows its active style and the × that resets it.
 */
export function FilterCombobox({
    id,
    ariaLabel,
    options,
    value,
    onValueChange,
    onClear,
}: {
    id?: string;
    ariaLabel: string;
    options: ComboboxOption[];
    value: string;
    onValueChange: (value: string) => void;
    onClear: (() => void) | null;
}) {
    return (
        <div
            className="relative w-full md:w-40 md:shrink-0"
            role="group"
            aria-label={ariaLabel}
        >
            <Combobox
                id={id}
                options={options}
                value={value}
                onValueChange={onValueChange}
                triggerClassName={filterTriggerClass(onClear !== null)}
                ariaLabel={ariaLabel}
            />
            {onClear && (
                <FilterClearButton label={ariaLabel} onClear={onClear} />
            )}
        </div>
    );
}

/** Select trigger for fixed toolbar filters; `onClear` as on FilterCombobox. */
export function FilterSelectTrigger({
    onClear,
    ...props
}: Omit<ComponentProps<typeof SelectTrigger>, 'className' | 'size'> & {
    'aria-label': string;
    onClear: (() => void) | null;
}) {
    return (
        <div className="relative w-full md:w-40 md:shrink-0">
            <SelectTrigger
                {...props}
                className={filterTriggerClass(onClear !== null)}
            />
            {onClear && (
                <FilterClearButton
                    label={props['aria-label']}
                    onClear={onClear}
                />
            )}
        </div>
    );
}

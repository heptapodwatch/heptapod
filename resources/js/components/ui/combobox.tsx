import { useId, useState, type ReactNode } from 'react';
import { Popover } from 'radix-ui';
import { Check, ChevronDown, Plus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
    Command,
    CommandInput,
    CommandList,
    CommandItem,
    CommandGroup,
    CommandEmpty,
} from '@/components/ui/command';
import { selectTriggerClassName } from '@/components/ui/select';
import { TooltipHint } from '@/components/truncated-text';
import { cn } from '@/lib/utils';

export type ComboboxOption = {
    value: string;
    label: string;
    /** Leading identity image (SiteAvatar, ClientMark) in the list and the trigger. */
    mark?: ReactNode;
    /** Muted second line in the list; the trigger shows it only in its tooltip. */
    secondary?: string;
    /** Extra text the search matches against (host, offset, client…). */
    searchText?: string;
    /** Options sharing a group render under one heading, in first-seen order. */
    group?: string;
    /** How a chosen option reads in MultiCombobox chips and trigger when its label alone is ambiguous ("Website (shop.example)"). */
    selectedLabel?: string;
};
export type ComboboxCreate = {
    label: (name: string) => string;
    onCreate: (name: string) => void;
};

function optionContent(option: ComboboxOption): ReactNode {
    return (
        <>
            {option.mark}
            {option.secondary ? (
                <span className="min-w-0">
                    <span className="block truncate">{option.label}</span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {option.secondary}
                    </span>
                </span>
            ) : (
                <span className="min-w-0 truncate">{option.label}</span>
            )}
        </>
    );
}

/** The trigger shows the selected value on one line: mark + label, left-aligned and truncated. */
function SelectedValue({ option }: { option: ComboboxOption }) {
    return (
        <span className="flex min-w-0 items-center gap-2">
            {option.mark}
            <TooltipHint content={option.secondary}>
                <span className="min-w-0 truncate text-left">{option.label}</span>
            </TooltipHint>
        </span>
    );
}

function matches(option: ComboboxOption, query: string): boolean {
    if (!query) return true;
    const haystack =
        `${option.label} ${option.secondary ?? ''} ${option.searchText ?? ''}`.toLocaleLowerCase();
    return query
        .toLocaleLowerCase()
        .split(/\s+/)
        .every((part) => haystack.includes(part));
}

/** Grouped rendering shared by single and multi pickers; groups keep first-seen order. */
function grouped(matching: ComboboxOption[]): [string | null, ComboboxOption[]][] {
    const groups: [string | null, ComboboxOption[]][] = [];
    for (const option of matching) {
        const key = option.group ?? null;
        const existing = groups.find(([group]) => group === key);
        if (existing) existing[1].push(option);
        else groups.push([key, [option]]);
    }
    return groups;
}

export function Combobox({
    id,
    options,
    value,
    onValueChange,
    create,
    disabled = false,
    invalid = false,
    placeholder,
    triggerClassName,
    describedBy,
    ariaLabel,
    required = false,
}: {
    id?: string;
    options: ComboboxOption[];
    value: string;
    onValueChange: (value: string) => void;
    create?: ComboboxCreate;
    disabled?: boolean;
    invalid?: boolean;
    placeholder?: string;
    triggerClassName?: string;
    describedBy?: string;
    ariaLabel?: string;
    required?: boolean;
}) {
    const { t } = useTranslation();
    const popupId = useId();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const selected = options.find((option) => option.value === value);
    const name = query.trim();
    const matching = options.filter((option) => matches(option, name));
    const select = (option: ComboboxOption) => {
        onValueChange(option.value);
        setOpen(false);
    };
    return (
        <Popover.Root
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setQuery('');
            }}
        >
            <Popover.Trigger
                id={id}
                type="button"
                role="combobox"
                aria-controls={popupId}
                aria-expanded={open}
                aria-haspopup="dialog"
                aria-invalid={invalid || undefined}
                aria-describedby={describedBy}
                aria-label={ariaLabel}
                aria-required={required || undefined}
                disabled={disabled}
                className={cn(selectTriggerClassName, 'w-full', triggerClassName)}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        setOpen(true);
                    }
                }}
            >
                {selected ? (
                    <SelectedValue option={selected} />
                ) : (
                    <span className="min-w-0 truncate text-left">
                        {placeholder ?? t('Choose an option')}
                    </span>
                )}
                <ChevronDown className="size-4 shrink-0 opacity-50" />
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Content
                    id={popupId}
                    aria-label={t('combobox.search')}
                    align="start"
                    sideOffset={4}
                    collisionPadding={8}
                    className="z-50 w-(--radix-popover-trigger-width) max-w-[calc(100vw-1rem)] origin-(--radix-popover-content-transform-origin) overflow-hidden rounded-lg border bg-popover text-popover-foreground shadow-overlay motion-popover"
                >
                    <Command label={t('combobox.search')} shouldFilter={false}>
                        <CommandInput
                            aria-label={t('combobox.search')}
                            placeholder={t('combobox.search')}
                            value={query}
                            onValueChange={setQuery}
                        />
                        <CommandList label={t('combobox.options')}>
                            {!create && matching.length === 0 && (
                                <CommandEmpty>{t('combobox.empty')}</CommandEmpty>
                            )}
                            {grouped(matching).map(([group, items]) => (
                                <CommandGroup key={group ?? ''} heading={group ?? undefined}>
                                    {items.map((option) => (
                                        <CommandItem
                                            key={option.value}
                                            value={option.value}
                                            onSelect={() => select(option)}
                                        >
                                            {optionContent(option)}
                                            {value === option.value && (
                                                <Check className="ml-auto size-4 shrink-0" />
                                            )}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            ))}
                            {create && (
                                <CommandGroup>
                                    <CommandItem
                                        value="__create__"
                                        onSelect={() => {
                                            setOpen(false);
                                            create.onCreate(name);
                                        }}
                                    >
                                        <Plus className="size-4" />
                                        {create.label(name)}
                                    </CommandItem>
                                </CommandGroup>
                            )}
                        </CommandList>
                    </Command>
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}

/** The one multi-select pattern: Combobox checkmarks that stay open, plus removable chips below. */
export function MultiCombobox({
    id,
    options,
    values,
    onValuesChange,
    disabled = false,
    invalid = false,
    placeholder,
    describedBy,
    ariaLabel,
    labelledBy,
}: {
    id?: string;
    options: ComboboxOption[];
    values: string[];
    onValuesChange: (values: string[]) => void;
    disabled?: boolean;
    invalid?: boolean;
    placeholder?: string;
    describedBy?: string;
    ariaLabel?: string;
    labelledBy?: string;
}) {
    const { t } = useTranslation();
    const popupId = useId();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const name = query.trim();
    const matching = options.filter((option) => matches(option, name));
    const chosen = values
        .map((value) => options.find((option) => option.value === value))
        .filter((option) => option !== undefined);
    const toggle = (option: ComboboxOption) =>
        onValuesChange(
            values.includes(option.value)
                ? values.filter((value) => value !== option.value)
                : [...values, option.value],
        );
    return (
        <div className="space-y-2">
            <Popover.Root
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    setQuery('');
                }}
            >
                <Popover.Trigger
                    id={id}
                    type="button"
                    role="combobox"
                    aria-controls={popupId}
                    aria-expanded={open}
                    aria-haspopup="dialog"
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy}
                    aria-label={ariaLabel}
                    aria-labelledby={labelledBy}
                    disabled={disabled}
                    className={cn(selectTriggerClassName, 'w-full')}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            setOpen(true);
                        }
                    }}
                >
                    <span className="min-w-0 truncate text-left">
                        {chosen.length > 0
                            ? chosen
                                  .map((option) => option.selectedLabel ?? option.label)
                                  .join(', ')
                            : (placeholder ?? t('Choose an option'))}
                    </span>
                    <ChevronDown className="size-4 shrink-0 opacity-50" />
                </Popover.Trigger>
                <Popover.Portal>
                    <Popover.Content
                        id={popupId}
                        aria-label={t('combobox.search')}
                        align="start"
                        sideOffset={4}
                        collisionPadding={8}
                        className="z-50 w-(--radix-popover-trigger-width) max-w-[calc(100vw-1rem)] origin-(--radix-popover-content-transform-origin) overflow-hidden rounded-lg border bg-popover text-popover-foreground shadow-overlay motion-popover"
                    >
                        <Command label={t('combobox.search')} shouldFilter={false}>
                            <CommandInput
                                aria-label={t('combobox.search')}
                                placeholder={t('combobox.search')}
                                value={query}
                                onValueChange={setQuery}
                            />
                            <CommandList label={t('combobox.options')}>
                                {matching.length === 0 && (
                                    <CommandEmpty>{t('combobox.empty')}</CommandEmpty>
                                )}
                                {grouped(matching).map(([group, items]) => (
                                    <CommandGroup key={group ?? ''} heading={group ?? undefined}>
                                        {items.map((option) => (
                                            <CommandItem
                                                key={option.value}
                                                value={option.value}
                                                onSelect={() => toggle(option)}
                                            >
                                                {optionContent(option)}
                                                {values.includes(option.value) && (
                                                    <Check className="ml-auto size-4 shrink-0" />
                                                )}
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                ))}
                            </CommandList>
                        </Command>
                    </Popover.Content>
                </Popover.Portal>
            </Popover.Root>
            {chosen.length > 0 && (
                <div className="flex flex-wrap gap-1.5" role="list" aria-label={t('combobox.selected')}>
                    {chosen.map((option) => (
                        <span
                            role="listitem"
                            key={option.value}
                            className="inline-flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pr-1 pl-2.5 text-xs"
                        >
                            {option.selectedLabel ?? option.label}
                            <button
                                type="button"
                                disabled={disabled}
                                aria-label={t('combobox.remove', {
                                    name: option.selectedLabel ?? option.label,
                                })}
                                className="flex size-6 shrink-0 items-center justify-center rounded-full hover:bg-accent disabled:opacity-50"
                                onClick={() => toggle(option)}
                            >
                                <X className="size-3" />
                            </button>
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

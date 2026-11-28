import type { ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { SearchCombobox } from '@/components/search-combobox';
import InputError, { fieldErrorProps } from '@/components/input-error';
import type { ComboboxOption } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';

/**
 * The one picker that assigns something to a client. With `onCreateClient` it ends in "Create client …",
 * which the caller answers by opening ClientCreateSheet and selecting the created client.
 * Pickers that only scope or filter by client use SearchCombobox / FilterCombobox instead.
 */
export function ClientField({
    leadingOptions = [],
    onCreateClient,
    error,
    id = 'site-client',
    ariaLabel,
    ...comboboxProps
}: Omit<
    ComponentProps<typeof SearchCombobox<'clients'>>,
    'kind' | 'leadingOptions' | 'create'
> & {
    /** Choices listed before the clients, e.g. "Use the team assignment". */
    leadingOptions?: ComboboxOption[];
    onCreateClient?: (name: string) => void;
    error?: string;
    /** Names the field where a column header already labels it; the visible "Client" label is then left out. */
    ariaLabel?: string;
}) {
    const { t } = useTranslation();
    return (
        <div className="space-y-2">
            {!ariaLabel && <Label htmlFor={id}>{t('Client')}</Label>}
            <SearchCombobox
                kind="clients"
                id={id}
                ariaLabel={ariaLabel}
                leadingOptions={leadingOptions}
                invalid={!!error}
                describedBy={fieldErrorProps(id, error)['aria-describedby']}
                create={
                    onCreateClient && {
                        label: (name) =>
                            name
                                ? t('clients.create_inline', { name })
                                : t('New client'),
                        onCreate: onCreateClient,
                    }
                }
                {...comboboxProps}
            />
            <InputError field={id} message={error} />
        </div>
    );
}

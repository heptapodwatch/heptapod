import type { ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { ClientMark } from '@/components/client-mark';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';

/**
 * The one picker that assigns something to a client. With `onCreateClient` it ends in "Create client …",
 * which the caller answers by opening ClientCreateSheet and selecting the created client.
 * Pickers that only scope or filter by client use EntityCombobox / FilterCombobox instead.
 */
export function ClientField({
    clients,
    leadingOptions = [],
    onCreateClient,
    error,
    id = 'site-client',
    ariaLabel,
    ...comboboxProps
}: Omit<
    ComponentProps<typeof Combobox>,
    'options' | 'create' | 'invalid' | 'describedBy'
> & {
    clients: Heptapod.Data.Ui.ClientData[];
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
            <Combobox
                id={id}
                ariaLabel={ariaLabel}
                options={[
                    ...leadingOptions,
                    ...clients.map((client) => ({
                        value: String(client.id),
                        label: client.name,
                        mark: <ClientMark client={client} small />,
                    })),
                ]}
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

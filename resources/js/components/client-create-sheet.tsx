import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    ClientFormFields,
    clientFormValues,
    type ClientFormValues,
} from '@/components/client-form-fields';
import InputError from '@/components/input-error';
import { SheetForm } from '@/components/sheet-form';
import { Sheet } from '@/components/ui/sheet';
import {
    HttpValidationError,
    httpErrorMessage,
    useHttpRequest,
} from '@/hooks/use-http-request';
import { store } from '@/routes/clients';

export function ClientCreateSheet({
    initialName,
    timezones,
    onClose,
    onCreated,
}: {
    initialName: string;
    /** Server IANA timezone list from the hosting page. */
    timezones: string[];
    onClose: () => void;
    onCreated: (client: Heptapod.Data.Ui.ClientData) => void;
}) {
    const { t } = useTranslation();
    const [failure, setFailure] = useState('');
    const form = useHttpRequest<ClientFormValues, Heptapod.Data.Ui.ClientData>({
        ...clientFormValues(),
        name: initialName,
    });
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <SheetForm
                title={t('Add client')}
                aria-busy={form.processing}
                onSubmit={() => {
                    setFailure('');
                    void form
                        .post(store.url())
                        .then(onCreated)
                        .catch((error: unknown) => {
                            if (!(error instanceof HttpValidationError))
                                setFailure(
                                    httpErrorMessage(
                                        error,
                                        t,
                                        'combobox.create_failed',
                                    ),
                                );
                        });
                }}
                actions={{
                    kind: 'create',
                    valid: form.data.name.trim() !== '',
                    busy: form.processing,
                    onCancel: onClose,
                }}
            >
                <ClientFormFields
                    data={form.data}
                    errors={form.errors}
                    setData={form.setData}
                    timezones={timezones}
                />
                {failure && <InputError message={failure} />}
            </SheetForm>
        </Sheet>
    );
}

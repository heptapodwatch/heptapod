import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export function LanguageSelect({
    id,
    name,
    value,
    onValueChange,
    error,
}: {
    id: string;
    name: string;
    value: string;
    onValueChange: (value: string) => void;
    error?: string;
}) {
    const { t } = useTranslation();
    const { supportedLocales } = usePage().props;

    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{t('Language')}</Label>
            <Select name={name} value={value} onValueChange={onValueChange}>
                <SelectTrigger
                    id={id}
                    className="w-full"
                    {...fieldErrorProps(id, error)}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent position="popper" align="start">
                    {supportedLocales.map(({ code, name }) => (
                        <SelectItem key={code} value={code} textValue={name}>
                            <span lang={code}>{name}</span>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError field={id} message={error} />
        </div>
    );
}

import { useTranslation } from 'react-i18next';
import { Textarea } from '@/components/ui/textarea';

export function ShownOnceSecret({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    const { t } = useTranslation();
    return (
        <div className="space-y-2">
            <label htmlFor="one-time-secret" className="text-sm font-medium">
                {label}
            </label>
            <Textarea
                id="one-time-secret"
                readOnly
                value={value}
                spellCheck={false}
                autoComplete="off"
                className="min-h-20 resize-none border-primary bg-primary/10 font-mono break-all"
                onFocus={(event) => event.target.select()}
                aria-describedby="one-time-secret-help"
            />
            <p
                id="one-time-secret-help"
                className="text-xs text-muted-foreground"
            >
                {t('Copy now. You will not see this again.')}
            </p>
        </div>
    );
}

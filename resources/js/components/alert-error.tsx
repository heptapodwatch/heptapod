import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export default function AlertError({
    errors,
    title,
}: {
    errors: string[];
    title?: string;
}) {
    const { t } = useTranslation();
    return (
        <Alert tone="danger">
            <AlertTitle>{t(title || 'Something went wrong.')}</AlertTitle>
            <AlertDescription>
                <ul className="list-inside list-disc">
                    {Array.from(new Set(errors)).map((error, index) => (
                        <li key={index}>{t(error)}</li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}

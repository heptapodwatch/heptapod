import { useTranslation } from 'react-i18next';
import { edit as apiTokens } from '@/routes/settings/api-tokens';
import { TextLink } from '@/components/text-link';

export function TasksApiNotice({ canManage }: { canManage: boolean }) {
    const { t } = useTranslation();
    return (
        <div className="space-y-2 text-sm text-muted-foreground">
            <p>
                {t(
                    canManage
                        ? 'Scheduled tasks are added through the API. Create a token in Settings → API tokens.'
                        : 'Scheduled tasks are added through the API. An Owner or Admin can add or change scheduled tasks.',
                )}
            </p>
            {canManage && (
                <TextLink href={apiTokens.url()} prefetch>
                    {t('API tokens')}
                </TextLink>
            )}
        </div>
    );
}

import { useFlashToast } from '@/hooks/use-flash-toast';
import { useTranslation } from 'react-i18next';
import { useAppearance } from '@/hooks/use-appearance';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();
    const { t } = useTranslation();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            containerAriaLabel={t('Notifications')}
            className="toaster group"
            position="bottom-right"
            toastOptions={{
                classNames: {
                    // Sonner's unlayered CSS overrides normal Tailwind utilities.
                    toast: 'text-sm! shadow-overlay!',
                },
            }}
            style={
                {
                    fontFamily: 'var(--font-sans)',
                    '--border-radius': 'var(--radius)',
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };

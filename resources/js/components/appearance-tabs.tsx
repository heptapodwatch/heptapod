import { Monitor, Moon, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { SegmentedControl } from '@/components/segmented-control';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';

export default function AppearanceTabs() {
    const { t } = useTranslation();
    const { appearance, updateAppearance } = useAppearance();
    return (
        <SegmentedControl
            label={t('Theme')}
            value={appearance}
            onValueChange={(value) => updateAppearance(value as Appearance)}
            options={[
                {
                    value: 'light',
                    label: (
                        <>
                            <Sun className="size-4" />
                            {t('Light')}
                        </>
                    ),
                },
                {
                    value: 'dark',
                    label: (
                        <>
                            <Moon className="size-4" />
                            {t('Dark')}
                        </>
                    ),
                },
                {
                    value: 'system',
                    label: (
                        <>
                            <Monitor className="size-4" />
                            {t('System')}
                        </>
                    ),
                },
            ]}
        />
    );
}

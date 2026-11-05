import { useTranslation } from 'react-i18next';
import { Link, router } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import {
    DropdownMenuLabel,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import type { User } from '@/types';

export const themeOptions = [
    { value: 'system', label: 'System' },
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
] as const satisfies readonly { value: Appearance; label: string }[];

/** Identity, the device theme and Log out. Settings has one entry: the sidebar footer item. */
export function UserMenuContent({ user }: { user: User }) {
    const { t } = useTranslation();
    const cleanup = useMobileNavigation();
    const { appearance, updateAppearance } = useAppearance();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuLabel
                id="user-menu-theme"
                className="text-xs font-normal text-muted-foreground"
            >
                {t('Theme')}
            </DropdownMenuLabel>
            <DropdownMenuRadioGroup
                aria-labelledby="user-menu-theme"
                value={appearance}
                onValueChange={(value) => updateAppearance(value as Appearance)}
            >
                {themeOptions.map((option) => (
                    <DropdownMenuRadioItem
                        key={option.value}
                        value={option.value}
                        // Keep the menu open so the theme change is visible before closing.
                        onSelect={(event) => event.preventDefault()}
                    >
                        {t(option.label)}
                    </DropdownMenuRadioItem>
                ))}
            </DropdownMenuRadioGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    {t('Log out')}
                </Link>
            </DropdownMenuItem>
        </>
    );
}

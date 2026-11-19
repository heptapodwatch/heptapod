import { PageContainer } from '@/components/page-container';
import { useTranslation } from 'react-i18next';
import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { SectionTabs } from '@/components/section-tabs';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import {
    activeNavigationEntry,
    settingsGroups,
    visibleNavigation,
} from '@/lib/navigation';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { t } = useTranslation();
    const { currentUrl } = useCurrentUrl();
    const { auth } = usePage().props;
    const items = visibleNavigation(auth).filter((entry) =>
        (settingsGroups as readonly string[]).includes(entry.group),
    );
    const groups = settingsGroups
        .map((group) => ({
            group,
            items: items.filter((entry) => entry.group === group),
        }))
        .filter((group) => group.items.length > 0);
    const activeItem = activeNavigationEntry(items, currentUrl);

    return (
        <PageContainer className="lg:flex-row lg:items-start lg:gap-10">
            <SectionTabs
                className="md:hidden"
                label={t('Settings')}
                value={activeItem?.href ?? ''}
                items={items.map((item) => ({
                    value: item.href,
                    href: item.href,
                    label: (
                        <>
                            <item.icon
                                aria-hidden
                                className="size-4 shrink-0"
                            />
                            {t(item.title)}
                        </>
                    ),
                }))}
            />
            {/* The settings sidebar reuses the app sidebar's group and item tokens. */}
            <aside className="-mx-2 hidden w-full shrink-0 md:block lg:w-[232px]">
                {groups.map(({ group, items: groupItems }) => (
                    <SidebarGroup key={group} className="py-0 pb-4">
                        <SidebarGroupLabel id={`settings-nav-${group}`}>
                            {t(group)}
                        </SidebarGroupLabel>
                        <nav aria-labelledby={`settings-nav-${group}`}>
                            <SidebarMenu className="flex-row flex-wrap lg:flex-col">
                                {groupItems.map((item) => {
                                    const active = item === activeItem;
                                    return (
                                        <SidebarMenuItem
                                            key={item.href}
                                            className="lg:w-full"
                                        >
                                            <SidebarMenuButton
                                                asChild
                                                isActive={active}
                                            >
                                                <Link
                                                    href={item.href}
                                                    aria-current={
                                                        active
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                    prefetch
                                                >
                                                    <item.icon aria-hidden />
                                                    <span>{t(item.title)}</span>
                                                </Link>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    );
                                })}
                            </SidebarMenu>
                        </nav>
                    </SidebarGroup>
                ))}
            </aside>
            <div className="min-w-0 flex-1 space-y-6">{children}</div>
        </PageContainer>
    );
}

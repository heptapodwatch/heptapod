import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AppLogo from '@/components/app-logo';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { t } = useTranslation();
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-6">
                        <Link
                            href="/"
                            prefetch
                            className="hp-brand-link rounded-sm [--brand-mark-h:28px] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <AppLogo />
                        </Link>
                        <div className="space-y-2 text-center">
                            <h1 className="text-page-title">
                                {title && t(title)}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {description && t(description)}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}

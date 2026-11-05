import type { PropsWithChildren } from 'react';
import { usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import tower from '@/images/invitation-tower.png';

export default function LoginLayout({ children }: PropsWithChildren) {
    const { instance, host } = usePage<{ instance: string; host: string }>()
        .props;

    return (
        <div className="flex min-h-svh bg-background text-foreground">
            <main className="flex min-w-0 flex-1 items-center justify-center px-6 py-12">
                <div className="flex w-full max-w-[380px] flex-col gap-[22px] [&_h1]:text-page-title">
                    <div className="flex items-center pb-1 [--brand-mark-h:28px]">
                        <AppLogo />
                    </div>
                    {children}
                </div>
            </main>
            <aside
                className="relative hidden w-[38.889%] shrink-0 overflow-hidden border-l border-border bg-[#0b0b0d] lg:block"
                aria-hidden="true"
            >
                <img
                    src={tower}
                    alt=""
                    decoding="async"
                    className="absolute inset-0 size-full object-cover opacity-90"
                />
                <div className="absolute right-10 bottom-10 left-10 flex flex-col gap-1 break-words text-[#edeae2]">
                    <p className="text-sm leading-[1.45] font-medium">
                        {instance}
                    </p>
                    <p className="font-mono text-xs leading-[1.45] text-[#edeae2]/60">
                        {host}
                    </p>
                </div>
            </aside>
        </div>
    );
}

import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function PageContainer({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            data-page-container
            className={cn(
                'mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-4 md:p-6',
                className,
            )}
            {...props}
        />
    );
}

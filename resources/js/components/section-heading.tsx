import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export const sectionHeadingClass = 'text-section';

export function SectionHeading({
    level = 2,
    className,
    ...props
}: ComponentProps<'h2'> & { level?: 2 | 3 | 4 }) {
    const Heading = level === 2 ? 'h2' : level === 3 ? 'h3' : 'h4';

    return (
        <Heading className={cn(sectionHeadingClass, className)} {...props} />
    );
}

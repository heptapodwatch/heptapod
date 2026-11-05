import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

export function SiteAvatar({
    name,
    iconUrl,
    iconBacking = 'none',
}: {
    name: string;
    iconUrl?: string | null;
    iconBacking?: Heptapod.Enums.SiteIconBacking;
}) {
    // One letter keeps the site fallback distinct from the two-letter ClientMark.
    const initials = (name.trim()[0] ?? '').toLocaleUpperCase();
    return (
        <Avatar
            size="sm"
            className="inline-flex size-6 shrink-0 rounded-md align-middle"
            aria-hidden="true"
        >
            {iconUrl && (
                <AvatarImage
                    src={iconUrl}
                    alt=""
                    className={cn(
                        'object-cover',
                        iconBacking === 'light-backing-in-dark' &&
                            'dark:bg-neutral-300',
                        iconBacking === 'dark-backing-in-light' &&
                            'bg-neutral-800 dark:bg-transparent',
                    )}
                />
            )}
            <AvatarFallback className="rounded-md border border-border bg-muted text-mark font-medium text-muted-foreground">
                {initials}
            </AvatarFallback>
        </Avatar>
    );
}

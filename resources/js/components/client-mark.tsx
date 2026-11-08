import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

type ClientMarkProps = {
    client: Pick<
        Heptapod.Data.Ui.ClientData,
        'name' | 'short_name' | 'accent_color' | 'avatar' | 'is_internal'
    >;
    small?: boolean;
};

export function ClientMark({ client, small = false }: ClientMarkProps) {
    const initials = client.name
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0] ?? '')
        .join('')
        .toLocaleUpperCase();

    return (
        <Avatar
            className={cn('shrink-0 rounded-full', small ? 'size-5' : 'size-9')}
            aria-hidden="true"
        >
            {client.avatar.url && (
                <AvatarImage
                    src={client.avatar.url}
                    alt=""
                    className={cn(
                        'object-cover',
                        client.avatar.backing === 'light-backing-in-dark' &&
                            'dark:bg-neutral-300',
                        client.avatar.backing === 'dark-backing-in-light' &&
                            'bg-neutral-800 dark:bg-transparent',
                    )}
                />
            )}
            <AvatarFallback
                className={cn(
                    'font-mono font-semibold',
                    client.is_internal ? 'text-muted-foreground' : 'text-white',
                    small ? 'text-mark' : 'text-xs',
                )}
                style={
                    client.is_internal
                        ? undefined
                        : { backgroundColor: client.accent_color ?? '#4B4B57' }
                }
            >
                {client.is_internal ? '—' : client.short_name || initials}
            </AvatarFallback>
        </Avatar>
    );
}

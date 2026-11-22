import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

export function UserInfo({
    user,
    showEmail = false,
    secondaryText,
}: {
    user: User;
    showEmail?: boolean;
    secondaryText?: string;
}) {
    const getInitials = useInitials();

    return (
        <>
            <Avatar className="h-8 w-8 overflow-hidden rounded-full">
                <AvatarImage src={user.avatar} alt={user.name} />
                <AvatarFallback className="rounded-lg bg-avatar-fallback text-avatar-fallback-foreground">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{user.name}</span>
                {(secondaryText !== undefined || showEmail) && (
                    <span className="truncate text-xs text-muted-foreground">
                        {secondaryText ?? user.email}
                    </span>
                )}
            </div>
        </>
    );
}

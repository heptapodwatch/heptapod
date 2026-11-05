import {
    Bell,
    BellRing,
    Hash,
    Mail,
    MessageCircle,
    Send,
    Users,
    Webhook,
    type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';

const channelIcons: Record<string, LucideIcon> = {
    mail: Mail,
    slack: Hash,
    discord: MessageCircle,
    microsoftTeams: Users,
    telegram: Send,
    pushover: Bell,
    ntfy: BellRing,
    webhook: Webhook,
};

/** The channel type's icon in a bordered tile; a paused channel gets a dashed border and muted ink. */
export function ChannelGlyph({
    channel,
    paused = false,
}: {
    channel: string;
    paused?: boolean;
}) {
    const Icon = channelIcons[channel] ?? Webhook;
    return (
        <span
            aria-hidden
            className={cn(
                'flex size-8 shrink-0 items-center justify-center rounded-lg border bg-background',
                paused && 'border-dashed',
            )}
        >
            <Icon
                className={cn(
                    'size-4',
                    paused ? 'text-muted-foreground' : 'text-foreground',
                )}
            />
        </span>
    );
}

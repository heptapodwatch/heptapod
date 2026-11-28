import { ClientMark } from '@/components/client-mark';
import { SiteAvatar } from '@/components/site-avatar';
import type { ComboboxOption } from '@/components/ui/combobox';

export function siteHost(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

type SiteLike = {
    id: number;
    name: string;
    client_name?: string | null;
    host?: string | null;
    url?: string;
    icon_url?: string | null;
    icon_backing?: Heptapod.Enums.SiteIconBacking;
    /** Client · address without scheme, unique to the site: the second line and, with the name, its chip. */
    detail?: string;
};

type ClientLike = {
    id: number;
    name: string;
    short_name?: string | null;
    accent_color?: string | null;
    avatar?: Heptapod.Data.Ui.ClientAvatarData | null;
    is_internal?: boolean;
};

/** Site rows are identifiable when names collide: avatar + name, then client · host or the deciding detail (list rows and trigger tooltip). */
export function siteComboboxOptions(sites: SiteLike[]): ComboboxOption[] {
    return sites.map((site) => {
        const host = site.host ?? (site.url ? siteHost(site.url) : null);
        const secondary =
            site.detail ??
            ([site.client_name, host].filter(Boolean).join(' · ') || undefined);
        return {
            value: String(site.id),
            label: site.name,
            secondary,
            selectedLabel: site.detail
                ? `${site.name} (${site.detail})`
                : undefined,
            searchText: secondary,
            mark: (
                <SiteAvatar
                    name={site.name}
                    iconUrl={site.icon_url}
                    iconBacking={site.icon_backing}
                />
            ),
        };
    });
}

/** Client rows: ClientMark + name (the client is the object, so it gets the avatar). */
export function clientComboboxOptions(clients: ClientLike[]): ComboboxOption[] {
    return clients.map((client) => ({
        value: String(client.id),
        label: client.name,
        mark: client.avatar ? (
            <ClientMark
                client={{
                    name: client.name,
                    short_name: client.short_name ?? null,
                    accent_color: client.accent_color ?? null,
                    avatar: client.avatar,
                    is_internal: client.is_internal ?? false,
                }}
                small
            />
        ) : undefined,
    }));
}

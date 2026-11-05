import type { TFunction } from 'i18next';

export type SettingsPage = Heptapod.Data.Ui.NotificationSettingsPageData;
export type Channel = Heptapod.Data.Ui.NotificationChannelData;
export type GridRow = SettingsPage['grid'][number];
export type Mode = Heptapod.Enums.NotificationMode;
/** category → mode, for the categories this recipient may see. */
export type Grid = Record<string, Mode>;

/** Column order of the grid; every row has a cell per column, an absent mode keeps its column empty. */
export const modes: Mode[] = ['immediate', 'digest', 'off'];

export const modeLabel: Record<Mode, string> = {
    immediate: 'Immediately',
    digest: 'Digest',
    off: 'Off',
};

/** The grid as the channel has it: every visible category, each with a known mode. */
export function channelGrid(channel: Channel, grid: GridRow[]): Grid {
    return Object.fromEntries(
        grid.map((row) => {
            const mode = channel.preferences[row.category];
            if (mode === undefined)
                throw new Error(
                    `Channel ${channel.id} has no ${row.category} cell.`,
                );
            return [row.category, mode];
        }),
    );
}

/** The grid the channel started with, or null when it is unknown (then nothing is marked as changed). */
export function channelDefaults(channel: Channel): Grid | null {
    return channel.defaults;
}

export function differsFromDefaults(grid: Grid, defaults: Grid | null) {
    return (
        defaults !== null &&
        Object.keys(grid).some(
            (category) => grid[category] !== defaults[category],
        )
    );
}

/** "a, b and c": running-text lists without a serial comma, in EN and DE alike. */
export function joinList(items: string[], and: string): string {
    if (items.length <= 1) return items.join('');
    return `${items.slice(0, -1).join(', ')} ${and} ${items.at(-1)}`;
}

/**
 * The channel's summary as one display-only sentence built from its grid:
 * "Gets outages, failures and recoveries immediately; warnings in the 08:00 digest. Not for shop.example."
 * Client channels name only their opted-in categories; everything else reaches a client only by hand.
 */
export function channelSentence({
    t,
    kind,
    grid,
    digestTime,
    mutedSites,
}: {
    t: TFunction;
    kind: Heptapod.Enums.RecipientKind;
    grid: Grid;
    digestTime: string;
    mutedSites: string[];
}): string {
    const names = (mode: Mode) =>
        Object.keys(grid)
            .filter((category) => grid[category] === mode)
            .map((category) =>
                t(`recipient_settings.category.${category}.plural`),
            );
    const and = t('recipient_settings.and');
    const now = joinList(names('immediate'), and);
    const digest = joinList(names('digest'), and);
    let sentence =
        now && digest
            ? t('recipient_settings.sentence.now_and_digest', {
                  now,
                  digest,
                  time: digestTime,
              })
            : now
              ? t('recipient_settings.sentence.now', { now })
              : digest
                ? t('recipient_settings.sentence.digest', {
                      digest,
                      time: digestTime,
                  })
                : t(
                      kind === 'client'
                          ? 'recipient_settings.sentence.client_nothing'
                          : 'recipient_settings.sentence.nothing',
                  );
    if (kind === 'client' && (now || digest))
        sentence += ` ${t('recipient_settings.sentence.client_rest')}`;
    if (mutedSites.length === 0) return sentence;
    return `${sentence} ${t('recipient_settings.sentence.not_for', {
        sites:
            mutedSites.length <= 2
                ? joinList(mutedSites, and)
                : t('recipient_settings.sentence.site_count', {
                      count: mutedSites.length,
                  }),
    })}`;
}

/** What identifies a channel in a list: the address, else its first public setting (a Slack channel, an ntfy server). */
export function channelTarget(channel: Channel): string | null {
    if (channel.address !== null) return channel.address;
    const shown = Object.values(channel.config).find(
        (value): value is string => typeof value === 'string' && value !== '',
    );
    return shown ?? null;
}

/** Server-computed identity shared by chips, sentences and picker details. */
export type SiteIdentity = SettingsPage['sites'][number]['identity'];

export function siteIdentities(
    sites: SettingsPage['sites'],
): Map<number, SiteIdentity> {
    return new Map(sites.map((site) => [site.id, site.identity]));
}

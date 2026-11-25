import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { TooltipHint } from '@/components/truncated-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

/** Human label per API ability; the raw scope (`checks:read`) stays reachable in a tooltip for API users. */
const abilityLabels: Record<string, string> = {
    '*': 'api_tokens.ability.all',
    'identity:read': 'api_tokens.ability.identity_read',
    'monitors:read': 'api_tokens.ability.monitors_read',
    'monitors:write': 'api_tokens.ability.monitors_write',
    'checks:read': 'api_tokens.ability.checks_read',
    'checks:write': 'api_tokens.ability.checks_write',
    'checks:run': 'api_tokens.ability.checks_run',
    'checks:snooze': 'api_tokens.ability.checks_snooze',
    'incidents:read': 'api_tokens.ability.incidents_read',
    'incidents:note': 'api_tokens.ability.incidents_note',
    'uptime:read': 'api_tokens.ability.uptime_read',
    'downtime:read': 'api_tokens.ability.downtime_read',
    'tags:read': 'api_tokens.ability.tags_read',
    'tags:write': 'api_tokens.ability.tags_write',
    'clients:read': 'api_tokens.ability.clients_read',
    'clients:write': 'api_tokens.ability.clients_write',
    'maintenance:read': 'api_tokens.ability.maintenance_read',
    'maintenance:write': 'api_tokens.ability.maintenance_write',
    'activity:read': 'api_tokens.ability.activity_read',
    'upcoming:read': 'api_tokens.ability.upcoming_read',
};

/** Consequential side effects, listed under the matrix while checked. */
const abilityConsequences: Record<string, string> = {
    'monitors:write': 'api_tokens.ability.monitors_write.consequence',
    'checks:write': 'api_tokens.ability.checks_write.consequence',
    'clients:write': 'api_tokens.ability.clients_write.consequence',
};

/** Matrix rows and grant pills, in display order; the key is the ability prefix. */
const areas = [
    'identity',
    'monitors',
    'checks',
    'incidents',
    'activity',
    'upcoming',
    'uptime',
    'downtime',
    'maintenance',
    'tags',
    'clients',
];
const areaOf = (ability: string) => ability.slice(0, ability.indexOf(':'));
const verbOf = (ability: string) => ability.slice(ability.indexOf(':') + 1);
/** `write` reads as "change"; any other verb is an area-specific action (run, snooze, note). */
const levelOf = (ability: string) =>
    verbOf(ability) === 'write' ? 'change' : verbOf(ability);
const levels = ['read', 'change', 'run', 'snooze', 'note'];

export function abilityLabel(ability: string): string {
    const label = abilityLabels[ability];
    if (!label) throw new Error(`Unknown API ability: ${ability}`);
    return label;
}

/**
 * The permission matrix, a form grid (not the data Table: no header band, no row hover). Rows are areas; Read and Change
 * are columns; every other ability of an area is a named checkbox in its Actions cell. Each checkbox carries its own word,
 * so every row reads by itself; below sm a row stacks. Checking one ability never checks another.
 */
export function AbilityMatrix({
    available,
    value,
    onChange,
    disabled,
}: {
    available: string[];
    value: string[];
    onChange: (abilities: string[]) => void;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
    const baseId = useId();
    const unknown = available.filter(
        (ability) => !areas.includes(areaOf(ability)),
    );
    if (unknown.length > 0)
        throw new Error(`API abilities without an area: ${unknown.join(', ')}`);
    const toggle = (ability: string, on: boolean) =>
        onChange(
            // Keep the server's ability order, whatever the click order.
            available.filter((candidate) =>
                candidate === ability ? on : value.includes(candidate),
            ),
        );
    const cell = (ability: string | undefined, word: string) =>
        ability ? (
            <span className="w-fit">
                <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 text-cell has-disabled:cursor-not-allowed has-disabled:text-disabled-foreground sm:min-h-8">
                    <Checkbox
                        value={ability}
                        aria-describedby={`${baseId}-${ability}`}
                        checked={value.includes(ability)}
                        disabled={disabled}
                        onCheckedChange={(on) => toggle(ability, on === true)}
                    />
                    {word}
                </label>
                {/* The plain ability name describes the checkbox; the visible word stays its name. */}
                <span id={`${baseId}-${ability}`} className="sr-only">
                    {t(abilityLabel(ability))}
                </span>
            </span>
        ) : (
            <span className="hidden sm:block" />
        );
    const checked = value.filter((ability) => abilityConsequences[ability]);
    return (
        <div className="space-y-2">
            <div className="sm:grid sm:grid-cols-[minmax(0,1fr)_5.5rem_5.5rem_max-content] sm:gap-x-4">
                {areas.map((area) => {
                    const own = available.filter(
                        (ability) => areaOf(ability) === area,
                    );
                    if (own.length === 0) return null;
                    return (
                        <div
                            key={area}
                            role="group"
                            aria-labelledby={`${baseId}-${area}`}
                            className="flex flex-col gap-x-4 border-t border-border-soft py-1.5 first:border-t-0 sm:col-span-4 sm:grid sm:grid-cols-subgrid sm:items-center"
                        >
                            <TooltipHint content={own.join(' · ')}>
                                <span
                                    id={`${baseId}-${area}`}
                                    className="w-fit text-cell font-medium sm:font-normal"
                                >
                                    {t(`api_tokens.area.${area}`)}
                                </span>
                            </TooltipHint>
                            <div className="flex gap-6 sm:contents">
                                {cell(
                                    own.find((a) => verbOf(a) === 'read'),
                                    t('api_tokens.matrix.read'),
                                )}
                                {cell(
                                    own.find((a) => verbOf(a) === 'write'),
                                    t('api_tokens.matrix.change'),
                                )}
                            </div>
                            <div className="flex flex-wrap gap-x-4">
                                {own
                                    .filter(
                                        (a) =>
                                            verbOf(a) !== 'read' &&
                                            verbOf(a) !== 'write',
                                    )
                                    .map((ability) => (
                                        <span key={ability}>
                                            {cell(
                                                ability,
                                                t(
                                                    `api_tokens.action.${verbOf(ability)}`,
                                                ),
                                            )}
                                        </span>
                                    ))}
                            </div>
                        </div>
                    );
                })}
            </div>
            {checked.length > 0 && (
                <ul className="space-y-0.5 text-xs text-muted-foreground">
                    {checked.map((ability) => (
                        <li key={ability}>
                            {t(abilityLabel(ability))}:{' '}
                            {t(abilityConsequences[ability]!)}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * A token's stored grant on one line: one pill per area with its levels and the raw scopes in the pill tooltip. More than
 * two areas collapse behind "+N", which expands the row inline. A grant equal to everything the role may issue right now
 * reads "Full access"; a later ability breaks that match. The legacy `*` keeps its own label.
 */
export function TokenGrant({
    abilities,
    grantable,
}: {
    abilities: string[];
    grantable: string[];
}) {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);
    if (abilities.includes('*'))
        return (
            <TooltipHint
                content={`${t('api_tokens.ability.all')} (*). ${t('api_tokens.grants.replace_wildcard')}`}
            >
                <Badge className="max-w-full justify-start font-normal text-status-warn">
                    <span className="truncate">
                        {t('api_tokens.ability.all')}
                    </span>
                </Badge>
            </TooltipHint>
        );
    if (
        JSON.stringify([...abilities].sort()) ===
        JSON.stringify([...grantable].sort())
    )
        return (
            <TooltipHint content={abilities.join(', ')}>
                <Badge className="font-normal">{t('Full access')}</Badge>
            </TooltipHint>
        );
    const groups = areas
        .map((area) => ({
            area,
            abilities: abilities
                .filter((ability) => areaOf(ability) === area)
                .sort(
                    (a, b) =>
                        levels.indexOf(levelOf(a)) - levels.indexOf(levelOf(b)),
                ),
        }))
        .filter((group) => group.abilities.length > 0);
    const shown = expanded ? groups : groups.slice(0, 2);
    const hidden = groups.length - shown.length;
    return (
        <span
            className={cn(
                'flex min-w-0 flex-wrap items-center justify-end gap-1 min-[87.5rem]:justify-start',
                !expanded &&
                    'min-[87.5rem]:flex-nowrap min-[87.5rem]:overflow-hidden',
            )}
        >
            {shown.map((group) => (
                <TooltipHint
                    key={group.area}
                    content={group.abilities.join(', ')}
                >
                    <Badge className="font-normal">
                        {`${t(`api_tokens.area.${group.area}`)} · ${group.abilities.map((ability) => t(`api_tokens.level.${levelOf(ability)}`)).join(', ')}`}
                    </Badge>
                </TooltipHint>
            ))}
            {(hidden > 0 || expanded) && (
                <Button
                    type="button"
                    variant="ghost"
                    size="xs"
                    className="text-muted-foreground"
                    aria-expanded={expanded}
                    aria-label={
                        expanded
                            ? undefined
                            : t('api_tokens.grants.show_all', {
                                  count: abilities.length,
                              })
                    }
                    onClick={() => setExpanded(!expanded)}
                >
                    {expanded
                        ? t('api_tokens.grants.show_less')
                        : t('api_tokens.grants.more', { count: hidden })}
                </Button>
            )}
        </span>
    );
}

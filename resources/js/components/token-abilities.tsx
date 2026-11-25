import { useTranslation } from 'react-i18next';
import { CheckboxField } from '@/components/ui/checkbox';
import { Disclosure } from '@/components/ui/disclosure';
import { useFormat } from '@/hooks/use-format';

/** Human label per API ability; the raw scope (`checks:read`) is shown beside it for API users. */
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

/** Consequential side effects, shown under the checkbox. */
export const abilityConsequences: Record<string, string> = {
    'monitors:write': 'api_tokens.ability.monitors_write.consequence',
    'checks:write': 'api_tokens.ability.checks_write.consequence',
    'clients:write': 'api_tokens.ability.clients_write.consequence',
};

/** The permission checklist groups, in display order. */
export const abilityGroups: { label: string; abilities: string[] }[] = [
    { label: 'api_tokens.group.account', abilities: ['identity:read'] },
    {
        label: 'api_tokens.group.sites',
        abilities: ['monitors:read', 'monitors:write'],
    },
    {
        label: 'api_tokens.group.checks',
        abilities: [
            'checks:read',
            'checks:run',
            'checks:snooze',
            'checks:write',
        ],
    },
    {
        label: 'api_tokens.group.incidents',
        abilities: [
            'incidents:read',
            'incidents:note',
            'activity:read',
            'upcoming:read',
        ],
    },
    {
        label: 'api_tokens.group.reports',
        abilities: ['uptime:read', 'downtime:read'],
    },
    {
        label: 'api_tokens.group.maintenance',
        abilities: ['maintenance:read', 'maintenance:write'],
    },
    { label: 'api_tokens.group.tags', abilities: ['tags:read', 'tags:write'] },
    {
        label: 'api_tokens.group.clients',
        abilities: ['clients:read', 'clients:write'],
    },
];

export function abilityLabel(ability: string): string {
    const label = abilityLabels[ability];
    if (!label) throw new Error(`Unknown API ability: ${ability}`);
    return label;
}

/**
 * The grouped permission checklist: one CheckboxField per ability the role may grant, the raw scope beside its name, and
 * consequential side effects as the description. Checking one ability never checks another.
 */
export function AbilityChecklist({
    available,
    value,
    onChange,
    disabled,
    describedBy,
}: {
    available: string[];
    value: string[];
    onChange: (abilities: string[]) => void;
    disabled?: boolean;
    describedBy?: string;
}) {
    const { t } = useTranslation();
    const ungrouped = available.filter(
        (ability) =>
            !abilityGroups.some((group) => group.abilities.includes(ability)),
    );
    if (ungrouped.length > 0)
        throw new Error(`Ungrouped API abilities: ${ungrouped.join(', ')}`);
    return (
        <div className="space-y-3">
            {abilityGroups.map((group) => {
                const abilities = group.abilities.filter((ability) =>
                    available.includes(ability),
                );
                if (abilities.length === 0) return null;
                return (
                    <fieldset key={group.label} className="min-w-0">
                        <legend className="text-xs font-medium text-muted-foreground">
                            {t(group.label)}
                        </legend>
                        {abilities.map((ability) => (
                            <CheckboxField
                                key={ability}
                                value={ability}
                                checked={value.includes(ability)}
                                disabled={disabled}
                                aria-describedby={describedBy}
                                description={
                                    abilityConsequences[ability]
                                        ? t(abilityConsequences[ability])
                                        : undefined
                                }
                                onCheckedChange={(checked) =>
                                    onChange(
                                        // Keep the server's ability order, whatever the click order.
                                        available.filter((candidate) =>
                                            candidate === ability
                                                ? checked === true
                                                : value.includes(candidate),
                                        ),
                                    )
                                }
                            >
                                <span>{t(abilityLabel(ability))}</span>
                                <code className="font-mono text-xs text-muted-foreground">
                                    {ability}
                                </code>
                            </CheckboxField>
                        ))}
                    </fieldset>
                );
            })}
        </div>
    );
}

/** A token's stored grants: a count that expands to plain names with their raw API scopes. */
export function TokenAbilities({ abilities }: { abilities: string[] }) {
    const { t } = useTranslation();
    const format = useFormat();
    const wildcard = abilities.includes('*');
    return (
        <Disclosure
            className="text-cell"
            summary={
                wildcard
                    ? t('api_tokens.ability.all')
                    : t('api_tokens.grants.count', {
                          count: abilities.length,
                          formattedCount: format.number(abilities.length),
                      })
            }
        >
            <ul className="mt-1.5 space-y-1 pl-5.5" aria-label={t('Can')}>
                {abilities.map((ability) => (
                    <li key={ability} className="flex flex-wrap gap-x-2">
                        {/* The wildcard's plain name is already the summary. */}
                        {!wildcard && <span>{t(abilityLabel(ability))}</span>}
                        <code className="font-mono text-xs text-muted-foreground">
                            {ability}
                        </code>
                    </li>
                ))}
            </ul>
            {wildcard && (
                <p className="mt-1.5 pl-5.5 text-xs text-muted-foreground">
                    {t('api_tokens.grants.replace_wildcard')}
                </p>
            )}
        </Disclosure>
    );
}

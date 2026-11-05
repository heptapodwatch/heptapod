import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/** Human label per API ability; the raw scope (`checks:read`) stays in the tooltip for API users. */
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
    'maintenance:read': 'api_tokens.ability.maintenance_read',
    'maintenance:write': 'api_tokens.ability.maintenance_write',
    'activity:read': 'api_tokens.ability.activity_read',
    'upcoming:read': 'api_tokens.ability.upcoming_read',
};

export function abilityLabel(ability: string): string {
    const label = abilityLabels[ability];
    if (!label) throw new Error(`Unknown API ability: ${ability}`);
    return label;
}

/** Token abilities as human chips; each chip names its API scope in the shared tooltip. */
export function TokenAbilities({ abilities }: { abilities: string[] }) {
    const { t } = useTranslation();
    return (
        <ul className="flex flex-wrap gap-1" aria-label={t('Can')}>
            {abilities.map((ability) => (
                <li key={ability}>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Badge variant="outline" tabIndex={0}>
                                {t(abilityLabel(ability))}
                            </Badge>
                        </TooltipTrigger>
                        <TooltipContent>{ability}</TooltipContent>
                    </Tooltip>
                </li>
            ))}
        </ul>
    );
}

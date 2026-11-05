import {
    Activity,
    CircleHelp,
    Clock,
    Gauge,
    Globe,
    HeartPulse,
    Map as MapIcon,
    Network,
    ShieldAlert,
    ShieldCheck,
    Timer,
    Unlink,
    type LucideIcon,
} from 'lucide-react';
import type { CheckKey } from '@/types/monitoring';

export const checkTypes: Record<CheckKey, { label: string }> = {
    uptime: { label: 'checks.uptime.label' },
    performance: { label: 'checks.performance.label' },
    certificate_health: { label: 'checks.certificate_health.label' },
    domain: { label: 'checks.domain.label' },
    dns: { label: 'checks.dns.label' },
    sitemap: { label: 'checks.sitemap.label' },
    broken_links: { label: 'checks.broken_links.label' },
    mixed_content: { label: 'checks.mixed_content.label' },
    lighthouse: { label: 'checks.lighthouse.label' },
    cron: { label: 'checks.cron.label' },
    application_health: { label: 'checks.application_health.label' },
};
const icons: Record<string, LucideIcon> = {
    activity: Activity,
    timer: Timer,
    'shield-check': ShieldCheck,
    globe: Globe,
    network: Network,
    map: MapIcon,
    unlink: Unlink,
    'shield-alert': ShieldAlert,
    gauge: Gauge,
    clock: Clock,
    'heart-pulse': HeartPulse,
    'circle-help': CircleHelp,
};
export function checkIcon(iconId: string): LucideIcon {
    const icon = icons[iconId];
    if (!icon) throw new Error(`Unknown check icon: ${iconId}`);
    return icon;
}
export const checkSettingLabels: Record<string, string> = {
    http_verb: 'Method',
    timeout: 'Timeout',
    look_for_string: 'Body contains',
    absent_string: 'Body must not contain',
    expected_response_headers: 'Expected response headers',
    response_body_assertions: 'Response body rules',
    payload: 'Request fields',
    raw_payload: 'Raw request body',
    valid_status_codes: 'Accepted HTTP statuses',
    max_redirect_count: 'Maximum redirects',
    expected_final_redirect_url: 'Final redirect URL',
    http_client_headers: 'Headers',
    headers: 'Request headers',
    threshold_in_ms: 'Threshold',
    change_percentage: 'performance.minimum_increase',
    recovery_margin_percentage: 'performance.recovery_margin',
    expires_soon_threshold_in_days: 'Expiry warning',
    ignored_record_types: 'Skip record types',
    check_nameservers_in_sync: 'Check nameserver sync',
    result_url: 'URL',
    secret: 'Shared secret',
};

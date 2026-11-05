import type { CheckKey, FieldValue } from '@/types/monitoring';

type CheckOption = Heptapod.Data.Ui.CheckOptionData;
type Field = Heptapod.Contracts.Schema.Field;
export type SiteCreateStep = 'address' | 'client' | 'checks';
export type SiteCreateDraft = {
    step: SiteCreateStep;
    url: string;
    client_id: string;
    checks: CheckKey[];
    settings: Record<string, FieldValue>;
    openChecks: CheckKey[];
    changedChecks: CheckKey[];
    omitted: boolean;
};

function record(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function normalizedSiteUrl(value: string): string {
    const trimmed = value.trim();
    return /^[a-z][a-z\d+.-]*:/i.test(trimmed) ? trimmed : `https://${trimmed}`;
}

export function validSiteUrl(value: string): boolean {
    const url = normalizedSiteUrl(value);
    if (!value.trim() || !URL.canParse(url)) return false;
    const parsed = new URL(url);
    return (
        ['http:', 'https:'].includes(parsed.protocol) &&
        !parsed.username &&
        !parsed.password &&
        Boolean(parsed.hostname)
    );
}

function safeUrl(value: string): string {
    if (!value.trim()) return '';
    const normalized = normalizedSiteUrl(value);
    if (!URL.canParse(normalized)) return '';
    const url = new URL(normalized);
    if (!['http:', 'https:'].includes(url.protocol)) return '';
    url.username = '';
    url.password = '';
    url.search = '';
    url.hash = '';
    return url.href;
}

export function eligibleSiteCheck(check: CheckOption, url: string): boolean {
    return (
        check.key !== 'cron' &&
        !check.unavailable_reason &&
        (!check.requires_https ||
            !URL.canParse(normalizedSiteUrl(url)) ||
            new URL(normalizedSiteUrl(url)).protocol === 'https:')
    );
}

function safeField(field: Field, value: unknown, name: string): unknown {
    // Request headers and free-form request bodies can contain credentials even without secret schema metadata.
    if (
        field.type === 'secret' ||
        name.includes('headers') ||
        ['payload', 'raw_payload'].includes(name)
    )
        return undefined;
    if (value === null) return null;
    if (field.type === 'object') {
        if (!record(value)) return undefined;
        return Object.fromEntries(
            Object.entries(field.properties).flatMap(([key, child]) => {
                const safe = safeField(child, value[key], key);
                return safe === undefined ? [] : [[key, safe]];
            }),
        );
    }
    if (field.type === 'list') {
        if (!Array.isArray(value) || !field.items) return undefined;
        const itemField = field.items;
        return value
            .map((item) => safeField(itemField, item, name))
            .filter((item) => item !== undefined);
    }
    if (field.type === 'boolean')
        return typeof value === 'boolean' ? value : undefined;
    if (field.type === 'integer' || field.type === 'numeric')
        return typeof value === 'number' && Number.isFinite(value)
            ? value
            : undefined;
    if (typeof value !== 'string') return undefined;
    return field.targetUrl || field.rules.some((rule) => rule.startsWith('url'))
        ? safeUrl(value)
        : value;
}

function primitive(value: unknown): value is string | number | boolean | null {
    return (
        value === null ||
        typeof value === 'string' ||
        typeof value === 'boolean' ||
        (typeof value === 'number' && Number.isFinite(value))
    );
}

function fieldValue(value: unknown): value is FieldValue {
    return (
        primitive(value) ||
        (record(value) && Object.values(value).every(primitive)) ||
        (Array.isArray(value) &&
            (value.every((item) => typeof item === 'string') ||
                value.every(
                    (item) =>
                        record(item) &&
                        Object.values(item).every(
                            (property) => typeof property === 'string',
                        ),
                )))
    );
}

/** Parse storage as untrusted input and apply current schema, authorization and capability choices on both write and restore. */
export function siteCreateDraft(
    value: unknown,
    options: Heptapod.Data.Ui.AddSitePageData,
): SiteCreateDraft {
    const input = record(value) ? value : {};
    const url = typeof input.url === 'string' ? safeUrl(input.url) : '';
    const parsedUrl =
        typeof input.url === 'string' &&
        URL.canParse(normalizedSiteUrl(input.url))
            ? new URL(normalizedSiteUrl(input.url))
            : null;
    const keys = (value: unknown, eligible = false) =>
        options.checks
            .filter(
                (check) =>
                    Array.isArray(value) &&
                    value.includes(check.key) &&
                    (!eligible || eligibleSiteCheck(check, url)),
            )
            .map((check) => check.key);
    const settings: Record<string, FieldValue> = {};
    const suppliedSettings = record(input.settings) ? input.settings : {};
    for (const check of options.checks) {
        if (check.unavailable_reason) continue;
        for (const [name, field] of Object.entries(
            check.config_schema.fields,
        )) {
            const key = `${check.key}.${name}`;
            if (!(key in suppliedSettings)) continue;
            const safe = safeField(field, suppliedSettings[key], name);
            if (fieldValue(safe)) settings[key] = safe;
        }
    }
    const client_id = options.clients.some(
        (client) => String(client.id) === input.client_id,
    )
        ? String(input.client_id)
        : '';
    const step = !validSiteUrl(url)
        ? 'address'
        : input.step === 'checks' && client_id
          ? 'checks'
          : input.step === 'client' || input.step === 'checks'
            ? 'client'
            : 'address';
    return {
        step,
        url,
        client_id,
        checks: keys(input.checks, true),
        settings,
        openChecks: keys(input.openChecks),
        changedChecks: keys(input.changedChecks),
        omitted:
            input.omitted === true ||
            Boolean(
                parsedUrl &&
                (parsedUrl.username ||
                    parsedUrl.password ||
                    parsedUrl.search ||
                    parsedUrl.hash),
            ) ||
            JSON.stringify(settings) !== JSON.stringify(suppliedSettings),
    };
}

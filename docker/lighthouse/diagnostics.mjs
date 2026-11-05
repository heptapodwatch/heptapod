import credentialParameters from '../../config/credential-parameters.json' with { type: 'json' };

// Match SecretRedactor's minimum of four code points, including encoded echoes.
export function diagnosticSecrets(url, options, token) {
    const target = new URL(url);
    const values = [
        `http://run:${token}@lighthouse-proxy:3128`,
        token,
        ...Object.values(options.extraHeaders ?? {}),
        target.username,
        target.password,
        new URLSearchParams(
            `value=${target.username.replaceAll('+', '%2B')}`,
        ).get('value'),
        new URLSearchParams(
            `value=${target.password.replaceAll('+', '%2B')}`,
        ).get('value'),
    ];
    for (const parameter of target.search.slice(1).split('&')) {
        const value = parameter.includes('=')
            ? parameter.slice(parameter.indexOf('=') + 1)
            : '';
        values.push(value, new URLSearchParams(`value=${value}`).get('value'));
    }
    return values.filter(
        (value) => typeof value === 'string' && Array.from(value).length >= 4,
    );
}

function filterControls(text) {
    // Array.from materializes code points, so memory scales with the text size.
    return Array.from(text)
        .filter((character) => {
            const code = character.codePointAt(0);
            return code > 0x1f && (code < 0x7f || code > 0x9f);
        })
        .join('');
}

function appendRange(ranges, start, end) {
    const previous = ranges.at(-1);
    if (previous && start <= previous[1]) {
        previous[1] = Math.max(previous[1], end);
    } else {
        ranges.push([start, end]);
    }
}

// Guarantee: overlapping literal, JSON-escaped and URL-encoded echoes of candidates with at least four code points.
// Covers one percent-decode/encode level and control-filtered echoes; no recursive decoding or Unicode normalization guarantee.
// Intentional asymmetry: PHP's persistence pass also covers JSON \uXXXX, \/ and '+'-as-space query echoes; PHP case folding is ASCII-only.
// Node strips unmatched control characters from the output; PHP preserves them.
export function redactDiagnostics(text, secrets) {
    if (typeof text !== 'string') {
        throw new TypeError('Diagnostic text must be a string.');
    }
    const candidates = new Set();
    for (const secret of secrets) {
        if (typeof secret !== 'string') {
            throw new TypeError('Diagnostic secrets must be strings.');
        }
        const decoded = new URLSearchParams(
            `value=${secret.replaceAll('+', '%2B')}`,
        ).get('value');
        for (const value of [
            secret,
            decoded,
            filterControls(secret),
            filterControls(decoded),
        ]) {
            if (Array.from(value).length >= 4) candidates.add(value);
        }
    }
    const variants = new Set(['[redacted]']);
    for (const secret of candidates) {
        variants.add(secret);
        variants.add(JSON.stringify(secret).slice(1, -1));
        variants.add(
            encodeURIComponent(secret).replace(
                /[!'()*]/g,
                (character) =>
                    `%${character.charCodeAt(0).toString(16).toUpperCase()}`,
            ),
        );
        variants.add(
            new URLSearchParams({ value: secret }).toString().slice(6),
        );
    }
    const filtered = filterControls(text);
    let merged = [];
    for (const variant of variants) {
        const ranges = [];
        let existing = 0;
        const pattern = new RegExp(
            variant.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'),
            'gi',
        );
        let match;
        while ((match = pattern.exec(filtered)) !== null) {
            while (
                existing < merged.length &&
                merged[existing][0] <= match.index
            ) {
                appendRange(ranges, ...merged[existing++]);
            }
            appendRange(ranges, match.index, match.index + match[0].length);
            // Search the original text, including occurrences overlapping this one.
            pattern.lastIndex = match.index + 1;
        }
        while (existing < merged.length) {
            appendRange(ranges, ...merged[existing++]);
        }
        // Both streams are ascending; coalesce immediately instead of retaining every match.
        merged = ranges;
    }
    // Replace only after merging, so neither a mask nor a partial replacement hides an overlap.
    const parts = [];
    let copied = 0;
    for (const [start, end] of merged) {
        parts.push(filtered.slice(copied, start), '[redacted]');
        copied = end;
    }
    parts.push(filtered.slice(copied));
    return maskCredentialParameters(parts.join(''));
}

// Mirrors SecretRedactor::maskCredentialParameters: discovered URLs can carry tokens or signed-URL signatures that are
// not known secrets. Only the value is masked; the name and every other parameter stay readable.
const UrlQuery = /(https?:\/\/[^\s?#"'<>]*\?)([^\s#"'<>]*)/gi;
const QueryParameter = /(^|[&;])([^&;=]+)=([^&;]*)/g;
if (
    !Array.isArray(credentialParameters.names) ||
    credentialParameters.names.length === 0 ||
    !credentialParameters.names.every((name) => typeof name === 'string') ||
    typeof credentialParameters.suffixPattern !== 'string'
) {
    throw new TypeError('Invalid credential parameter policy.');
}
const CredentialNames = new Set(credentialParameters.names);
const CredentialNameSuffix = new RegExp(credentialParameters.suffixPattern);

function decodeName(name) {
    try {
        return decodeURIComponent(name.replaceAll('+', ' '));
    } catch {
        return name;
    }
}

// Compares the decoded name without array brackets, so ?%74oken=, ?token[]= and ?Token%5B0%5D= all match token.
function isCredentialName(name) {
    const normalized = decodeName(name)
        .replace(/\[\d*\]$/, '')
        .toLowerCase();
    return (
        CredentialNames.has(normalized) || CredentialNameSuffix.test(normalized)
    );
}

function maskCredentialParameters(text) {
    return text.replace(
        UrlQuery,
        (_, prefix, query) =>
            prefix +
            query.replace(
                QueryParameter,
                (__, separator, name, value) =>
                    `${separator}${name}=${value !== '' && isCredentialName(name) ? '[redacted]' : value}`,
            ),
    );
}

// The leading run of letters, spaces, '_' and '-' only. A dot right after the run means a host or
// file name was cut, so there is no safe name. A digit or '/' before a dot still leaves a host
// fragment, and a single-label host passes whole (TODO: reject single-label hosts).
export function safeEventName(text, secrets) {
    const redacted = redactDiagnostics(text, secrets);
    const name = redacted.match(/^[A-Za-z][A-Za-z _-]*/)?.[0] ?? '';
    if (redacted[name.length] === '.') return '';
    return Array.from(name.trimEnd()).slice(0, 64).join('');
}

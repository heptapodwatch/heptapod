import http, { validateHeaderName, validateHeaderValue } from 'node:http';
import { spawn } from 'node:child_process';
import { readFile, readdir } from 'node:fs/promises';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { diagnosticSecrets, redactDiagnostics, safeEventName } from './diagnostics.mjs';

const auditBudgetMs = 150000;
const categories = ['performance', 'accessibility', 'best-practices', 'seo'];
const stages = new Set([
    'browser_launch', 'navigation', 'gathering', 'auditing', 'reporting', 'unknown',
]);
const failureCodes = new Map([
    ['PAGE_HUNG', 'page_hung'],
    ['TARGET_CRASHED', 'page_crashed'],
    ['NO_FCP', 'no_content'],
    ['NOT_HTML', 'not_html'],
    ...['FAILED_DOCUMENT_REQUEST', 'ERRORED_DOCUMENT_REQUEST', 'NO_DOCUMENT_REQUEST', 'CHROME_INTERSTITIAL_ERROR', 'INSECURE_DOCUMENT_REQUEST'].map(code => [code, 'page_load_failed']),
]);
const knownNetworkCodes = new Set(['ERR_CERT_COMMON_NAME_INVALID', 'ERR_CERT_DATE_INVALID', 'ERR_CERT_AUTHORITY_INVALID', 'ERR_SSL_PROTOCOL_ERROR', 'ERR_CONNECTION_REFUSED', 'ERR_NAME_NOT_RESOLVED', 'ERR_TIMED_OUT', 'ERR_CONNECTION_TIMED_OUT']);
function normalizeError(error, secrets, nativeCode = null) {
    const workerCode = typeof error.code === 'string' && /^[A-Z_]{1,64}$/.test(error.code) ? error.code : null;
    const code = knownNetworkCodes.has(nativeCode) ? nativeCode : failureCodes.get(workerCode) ?? 'audit_incomplete';
    const detail = typeof error.message === 'string' ? error.message : null;
    // Unknown endings need the vendor code for diagnosis, within the same untrusted-message limits.
    const message = code === 'audit_incomplete' && workerCode
        ? detail ? `${workerCode}: ${detail}` : workerCode
        : detail;
    return {
        code,
        message: message === null ? null : Array.from(redactDiagnostics(message, secrets)).slice(0, 500).join(''),
    };
}
let busy = false;
async function waitForProcessGroup(pid) {
    const deadline = performance.now() + 2000;
    while (true) {
        let alive = false;
        for (const entry of await readdir('/proc')) {
            if (!/^\d+$/.test(entry)) continue;
            let stat;
            try {
                stat = await readFile(`/proc/${entry}/stat`, 'utf8');
            } catch (error) {
                if (error.code === 'ENOENT') continue;
                throw error;
            }
            const fields = stat.slice(stat.lastIndexOf(')') + 2).split(' ');
            if (Number(fields[2]) === pid && fields[0] !== 'Z' && fields[0] !== 'X') {
                alive = true;
                break;
            }
        }
        if (!alive) return;
        if (performance.now() >= deadline)
            throw new Error('Audit process group did not exit');
        await delay(25);
    }
}
async function audit(url, options, token, signal) {
    const secrets = diagnosticSecrets(url, options, token);
    let child;
    let kill;
    return new Promise((resolve, reject) => {
        const startedAt = performance.now();
        child = spawn(
            process.execPath,
            [fileURLToPath(new URL('./audit.mjs', import.meta.url))],
            { detached: true, stdio: ['pipe', 'pipe', 'ignore', 'pipe'] },
        );
        const chunks = [];
        let bytes = 0;
        let diagnostics = Buffer.alloc(0);
        let diagnosticBytes = 0;
        let stage = null;
        let stageStartedAt = startedAt;
        const stageElapsedMs = new Map();
        const finishStage = (now) => {
            if (stage !== null) {
                stageElapsedMs.set(stage, stageElapsedMs.get(stage) + now - stageStartedAt);
            }
            stageStartedAt = now;
        };
        const stageSeconds = () => Object.fromEntries([...stageElapsedMs].map(([key, ms]) =>
            [key, Math.min(Math.floor(ms / 1000), auditBudgetMs / 1000 + 5)],
        ));
        // Retain the last unrecognised event that had a safe name; unsafe names do not clear it.
        let lastUnrecognisedEvent;
        const eventFact = () => lastUnrecognisedEvent === undefined ? {} : {last_unrecognised_event: lastUnrecognisedEvent};
        let workerError = null;
        let failureCode = null;
        // ESRCH means the worker and its Chromium process group already exited.
        kill = () => {
            if (!child.pid) return;
            try {
                process.kill(-child.pid, 'SIGKILL');
            } catch (error) {
                if (error.code !== 'ESRCH') throw error;
            }
        };
        signal.addEventListener('abort', kill, { once: true });
        const timer = setTimeout(() => {
            failureCode ??= 'audit_budget_exceeded';
            kill();
        }, auditBudgetMs);
        child.stdio[3].on('data', (chunk) => {
            // Drop bytes beyond the cap, retaining only complete events and their last stage.
            const remaining = 16 * 1024 - diagnosticBytes;
            diagnosticBytes += Math.min(remaining, chunk.length);
            diagnostics = Buffer.concat([diagnostics, chunk.subarray(0, remaining)]);
            let newline;
            while ((newline = diagnostics.indexOf(10)) !== -1) {
                const line = diagnostics.subarray(0, newline).toString('utf8');
                diagnostics = diagnostics.subarray(newline + 1);
                try {
                    const event = JSON.parse(line);
                    if (event.stage !== undefined) {
                        if (!stages.has(event.stage)) throw new Error('Invalid worker stage');
                        if (stage !== event.stage) {
                            if (stage !== null) finishStage(performance.now());
                            stage = event.stage;
                            if (!stageElapsedMs.has(stage)) stageElapsedMs.set(stage, 0);
                        }
                    }
                    if (event.last_unrecognised_event !== undefined) {
                        if (typeof event.last_unrecognised_event !== 'string') throw new Error('Invalid worker event name');
                        const name = safeEventName(event.last_unrecognised_event, secrets);
                        if (name) lastUnrecognisedEvent = name;
                    }
                    if (event.error !== undefined) {
                        if (!event.error || typeof event.error !== 'object') throw new Error('Invalid worker error');
                        workerError = normalizeError(event.error, secrets);
                    }
                } catch (error) {
                    kill();
                    reject(error);
                    return;
                }
            }
        });
        if (signal.aborted) kill();
        child.stdout.on('data', (chunk) => {
            bytes += chunk.length;
            if (bytes > 8 * 1024 * 1024) {
                failureCode ??= 'report_too_large';
                kill();
            } else chunks.push(chunk);
        });
        child.once('error', (error) => {
            clearTimeout(timer);
            reject(error);
        });
        child.once('close', (code) => {
            clearTimeout(timer);
            finishStage(performance.now());
            if (failureCode) {
                resolve({ auditFailure: { code: failureCode, stage, stageSeconds: stageSeconds(), ...eventFact(), message: null, ...(failureCode === 'audit_budget_exceeded' ? { budgetSeconds: auditBudgetMs / 1000 } : {}) } });
            } else if (diagnostics.length && diagnosticBytes < 16 * 1024) {
                reject(new Error('Incomplete worker event'));
            } else if (code !== 0) {
                if (workerError) resolve({ auditFailure: { code: workerError.code, stage, stageSeconds: stageSeconds(), ...eventFact(), message: workerError.message } });
                else reject(new Error('Audit did not complete'));
            } else resolve({ report: Buffer.concat(chunks).toString('utf8'), stage, stageSeconds: stageSeconds(), ...eventFact() });
        });
        // Credentials travel over stdin, not process arguments, files or logs.
        child.stdin.once('error', reject);
        child.stdin.end(
            JSON.stringify({
                url,
                options,
                proxyUrl: `http://run:${token}@lighthouse-proxy:3128`,
            }),
        );
    }).finally(async () => {
        signal.removeEventListener('abort', kill);
        kill();
        if (child.pid) await waitForProcessGroup(child.pid);
    });
}
const server = http.createServer(async (request, response) => {
    const send = (status, value) => {
        response.writeHead(status, { 'content-type': 'application/json' });
        response.end(JSON.stringify(value));
    };
    if (request.method === 'GET' && request.url === '/health')
        return send(200, { status: 'up' });
    if (request.method !== 'POST' || request.url !== '/lighthouse')
        return send(404, { error: 'Not found' });
    if (busy) return send(429, { error: 'Busy' });
    busy = true;
    let status = 400;
    try {
        const chunks = [];
        let bytes = 0;
        for await (const chunk of request.iterator({
            destroyOnReturn: false,
        })) {
            bytes += chunk.length;
            if (bytes > 8 * 1024 * 1024) throw new Error('Invalid request');
            chunks.push(chunk);
        }
        const payload = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        if (payload === null || typeof payload !== 'object' || Object.keys(payload).some(key => !['url','options','proxyToken'].includes(key))) throw new Error('Invalid request');
        const {url,options = {},proxyToken} = payload;
        const target = new URL(url);
        if (
            typeof url !== 'string' ||
            url.length > 4096 ||
            !['http:', 'https:'].includes(target.protocol) ||
            target.username ||
            target.password
        )
            throw new Error('Invalid request');
        if (
            options === null ||
            typeof options !== 'object' ||
            Array.isArray(options) ||
            Object.keys(options).some(
                (key) =>
                    ![
                        'desktop',
                        'cpuSlowdownMultiplier',
                        'extraHeaders',
                    ].includes(key),
            ) ||
            (options.desktop !== undefined &&
                typeof options.desktop !== 'boolean') ||
            (options.cpuSlowdownMultiplier !== undefined &&
                (!Number.isFinite(options.cpuSlowdownMultiplier) ||
                    options.cpuSlowdownMultiplier < 1 ||
                    options.cpuSlowdownMultiplier > 20))
        )
            throw new Error('Invalid request');
        if (options.extraHeaders !== undefined) {
            if (
                options.extraHeaders === null ||
                typeof options.extraHeaders !== 'object' ||
                Array.isArray(options.extraHeaders) ||
                Object.keys(options.extraHeaders).length > 120
            )
                throw new Error('Invalid request');
            for (const [name, value] of Object.entries(options.extraHeaders)) {
                validateHeaderName(name);
                if (
                    name.length > 255 ||
                    typeof value !== 'string' ||
                    value.length > 10000
                )
                    throw new Error('Invalid request');
                validateHeaderValue(name, value);
            }
        }
        if (typeof proxyToken !== 'string' || !/^[a-f0-9]{64}$/.test(proxyToken)) throw new Error('Invalid proxy token');
        status = 502;
        const controller = new AbortController();
        response.once('close', () => controller.abort());
        const result = await audit(url, options, proxyToken, controller.signal);
        if (result.auditFailure) return send(200, { auditFailure: result.auditFailure });
        const lhr = JSON.parse(result.report);
        if (Object.hasOwn(lhr, 'load_timed_out') && typeof lhr.load_timed_out !== 'boolean')
            throw new Error('Invalid worker load timeout flag');
        if (lhr.runtimeError) {
            // Chromium's native navigation failure belongs to the target, not the audit service's HTTP status.
            const nativeCode = typeof lhr.runtimeError.message === 'string'
                ? /net::(ERR_[A-Z_]+)/.exec(lhr.runtimeError.message)?.[1]
                : null;
            const error = normalizeError(lhr.runtimeError, diagnosticSecrets(url, options, proxyToken), nativeCode);
            return send(200, { runtimeError: error, target_status_code: lhr.target_status_code, target_host: lhr.target_host });
        }
        if (typeof lhr.lighthouseVersion !== 'string' || !lhr.audits)
            throw new Error('Invalid LHR');
        const unscored = categories.filter(key =>
            !Number.isFinite(lhr.categories?.[key]?.score) ||
            lhr.categories[key].score < 0 || lhr.categories[key].score > 1,
        );
        if (unscored.length) return send(200, {
            auditFailure: { code: 'report_unscored', stage: result.stage, stageSeconds: result.stageSeconds, ...(result.last_unrecognised_event ? {last_unrecognised_event: result.last_unrecognised_event} : {}), message: unscored.join(', ') },
        });
        send(200, lhr);
    } catch {
        // Boundary diagnostics deliberately contain no vendor error, URL or credential.
        send(status, {
            error:
                status === 400
                    ? 'Invalid Lighthouse request'
                    : 'Lighthouse audit did not complete',
        });
    } finally {
        busy = false;
    }
});
server.requestTimeout = 5000;
server.listen(3000, '0.0.0.0');

import lighthouse, { defaultConfig, desktopConfig } from 'lighthouse';
import log from 'lighthouse-logger';
import { UIStrings } from 'lighthouse/core/gather/driver/navigation.js';
import { writeSync } from 'node:fs';
import { launchBrowser } from './browser.mjs';
import { diagnosticSecrets, safeEventName } from './diagnostics.mjs';

// Prefixes come from Lighthouse 13.5.0. Never forward status text: navigation includes the URL.
const stagePrefixes = [
    ['Connecting to browser', 'browser_launch'],
    ['Navigating to ', 'navigation'],
    ['Preparing target for navigation mode', 'navigation'],
    ['Preparing network conditions', 'navigation'],
    ['Gather phase', 'gathering'],
    ['Audit phase', 'auditing'],
    ['Getting artifact: ', 'gathering'],
    ['Trace Engine total', 'gathering'],
    ['Trace Engine parse', 'gathering'],
    ['Trace Engine insights', 'gathering'],
    ['Analyzing and running audits...', 'auditing'],
    ['Auditing: ', 'auditing'],
    ['Generating results...', 'reporting'],
];
const neutralPrefixes = [
    'Computing artifact: ',
    'Cleaning browser cache',
    'Cleaning origin data',
    'Benchmarking machine',
    'Getting browser version',
    'Host device pixel ratio',
    'Collect stacks',
    'Initialize config',
    'Resolve artifact definitions',
];
const writeEvent = (event) => writeSync(3, JSON.stringify(event) + '\n');
let lastStage = 'browser_launch';
let secrets = [];
log.events.on('status', ([, message]) => {
    const stage = stagePrefixes.find(([prefix]) =>
        message.startsWith(prefix),
    )?.[1];
    if (stage !== undefined) {
        if (stage !== lastStage) writeEvent({ stage });
        lastStage = stage;
        return;
    }
    if (neutralPrefixes.some((prefix) => message.startsWith(prefix))) return;
    const name = safeEventName(message, secrets);
    // Absent means no safe name; unrecognised events never erase a known stage.
    writeEvent({
        stage: lastStage,
        ...(name ? { last_unrecognised_event: name } : {}),
    });
});

try {
    let input = '';
    for await (const chunk of process.stdin) input += chunk;
    const { url, options, proxyUrl } = JSON.parse(input);
    secrets = diagnosticSecrets(url, options, new URL(proxyUrl).password);
    writeEvent({ stage: 'browser_launch' });
    const { browser, close } = await launchBrowser(proxyUrl);
    const origin = new URL(url).origin;
    const extraHeaders = options.extraHeaders ?? {};
    const names = new Set(
        Object.keys(extraHeaders).map((name) => name.toLowerCase()),
    );
    try {
        const page = await browser.newPage();
        await page.setBypassServiceWorker(true);
        // WebRTC has non-HTTP transports; remove its entry points before any document script runs.
        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(globalThis, 'RTCPeerConnection', {
                value: undefined,
                configurable: false,
            });
            Object.defineProperty(globalThis, 'webkitRTCPeerConnection', {
                value: undefined,
                configurable: false,
            });
        });
        await page.setRequestInterception(true);
        let targetHost = new URL(url).hostname;
        page.on('request', async (request) => {
            if (
                request.isNavigationRequest() &&
                request.frame() === page.mainFrame()
            ) {
                targetHost = new URL(request.url()).hostname;
            }
            // Remove inherited overrides too: redirects must never carry credentials across origins.
            const headers = Object.fromEntries([
                ...Object.entries(request.headers()).filter(
                    ([name]) => !names.has(name.toLowerCase()),
                ),
                ...Object.entries(
                    new URL(request.url()).origin === origin
                        ? extraHeaders
                        : {},
                ),
            ]);
            await request.continue({ headers });
        });
        const preset = options.desktop ? desktopConfig : defaultConfig;
        const config = {
            ...preset,
            settings: {
                ...preset.settings,
                throttling: {
                    ...preset.settings?.throttling,
                    cpuSlowdownMultiplier:
                        options.cpuSlowdownMultiplier ??
                        (options.desktop ? 1 : 4),
                },
            },
        };
        let targetStatus = null;
        page.on('response', (response) => {
            if (
                response.request().isNavigationRequest() &&
                response.frame() === page.mainFrame()
            ) {
                targetStatus = response.status();
            }
        });
        const result = await lighthouse(
            url,
            { logLevel: 'silent' },
            config,
            page,
        );
        writeEvent({ stage: 'reporting' });
        process.stdout.write(
            JSON.stringify({
                ...result.lhr,
                ...(result.lhr.runWarnings?.includes(UIStrings.warningTimeout)
                    ? { load_timed_out: true }
                    : {}),
                target_status_code: targetStatus,
                target_host: targetHost,
            }),
        );
    } finally {
        await close();
    }
} catch (error) {
    writeEvent({
        error: {
            code: error.code ?? null,
            message: String(error.friendlyMessage ?? error.message),
        },
    });
    process.exitCode = 1;
}

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { request } from 'playwright';

const upstream = process.env.LOGIN_FAILURES_ORIGIN;
const password = process.env.LOGIN_FAILURES_PASSWORD;
assert.ok(
    upstream,
    'Set LOGIN_FAILURES_ORIGIN to the production nginx proof URL',
);
assert.ok(
    password,
    'Set LOGIN_FAILURES_PASSWORD to the existing disposable demo password',
);
const publicOrigin = 'https://monitor.example.com:8443';

for (const [locale, throttleTitle, expiredTitle] of [
    ['en', 'Too Many Requests', 'Session expired · Heptapod'],
    ['de', 'Zu viele Anfragen', 'Sitzung abgelaufen · Heptapod'],
]) {
    await test(`raw production login failures and session recovery in ${locale}`, async () => {
        const context = await request.newContext({
            baseURL: upstream,
            extraHTTPHeaders: {
                host: 'monitor.example.com:8443',
                accept: 'text/html',
                referer: `${publicOrigin}/login`,
            },
            maxRedirects: 0,
        });
        const cookies = new Map([['locale', { value: locale }]]);
        const send = async (method, path, form) => {
            const headers = {
                cookie: [...cookies]
                    .map(([name, cookie]) => `${name}=${cookie.value}`)
                    .join('; '),
            };
            if (method === 'POST') {
                assert.ok(
                    cookies.has('XSRF-TOKEN'),
                    'Own XSRF cookie required',
                );
                headers['x-xsrf-token'] = decodeURIComponent(
                    cookies.get('XSRF-TOKEN').value,
                );
            }
            let response;
            try {
                response = await context.fetch(path, {
                    method,
                    headers,
                    form,
                    maxRedirects: 0,
                });
            } catch (error) {
                // Playwright transport diagnostics include request headers, including cookies.
                const code =
                    error instanceof Error
                        ? error.message.match(
                              /\b(?:ECONNREFUSED|ECONNRESET|ENOTFOUND|ETIMEDOUT|EAI_AGAIN)\b/,
                          )?.[0]
                        : undefined;
                throw new Error(
                    `Raw HTTP ${method} ${path} failed${code ? ` (${code})` : ''}; request headers/body redacted`,
                );
            }
            for (const header of response.headersArray()) {
                if (header.name.toLowerCase() !== 'set-cookie') continue;
                const [pair, ...parts] = header.value.split(';');
                const separator = pair.indexOf('=');
                assert.ok(separator > 0, 'Malformed Set-Cookie header');
                const attributes = new Map(
                    parts.map((part) => {
                        const [name, ...value] = part.trim().split('=');
                        return [name.toLowerCase(), value.join('=')];
                    }),
                );
                cookies.set(pair.slice(0, separator), {
                    value: pair.slice(separator + 1),
                    attributes,
                });
            }
            const responseHeaders = response.headers();
            console.log(
                JSON.stringify({
                    locale,
                    method,
                    path,
                    status: response.status(),
                    headers: Object.fromEntries(
                        [
                            'content-type',
                            'location',
                            'retry-after',
                            'x-ratelimit-limit',
                            'x-ratelimit-remaining',
                        ]
                            .filter((name) => name in responseHeaders)
                            .map((name) => [name, responseHeaders[name]]),
                    ),
                }),
            );
            return response;
        };
        const assertHtml = (response) => {
            assert.match(response.headers()['content-type'], /^text\/html\b/i);
        };
        const assertCookies = () => {
            const sessions = [...cookies.keys()].filter((name) =>
                /[-_]session$/.test(name),
            );
            assert.equal(sessions.length, 1, 'Exactly one session cookie');
            for (const name of [sessions[0], 'XSRF-TOKEN']) {
                assert.ok(
                    cookies.has(name),
                    'Session and XSRF cookies required',
                );
                const attributes = cookies.get(name).attributes;
                assert.equal(attributes.get('path'), '/');
                assert.equal(attributes.get('samesite'), 'lax');
                assert.equal(
                    attributes.has('domain'),
                    false,
                    'Cookies remain host-only',
                );
                assert.equal(attributes.has('httponly'), name === sessions[0]);
                assert.ok(
                    Number(attributes.get('max-age')) > 0,
                    'Cookies must have a positive lifetime',
                );
            }
            console.log(
                JSON.stringify({
                    locale,
                    cookies:
                        'session and XSRF present; host-only; Path=/; SameSite=Lax; session HttpOnly; values redacted',
                    secureObserved: {
                        session: cookies
                            .get(sessions[0])
                            .attributes.has('secure'),
                        xsrf: cookies
                            .get('XSRF-TOKEN')
                            .attributes.has('secure'),
                    },
                    secureQualification: 'not asserted by OPS fixture',
                }),
            );
            return sessions[0];
        };
        // The login limiter keys on email|IP. Throttling a per-locale address leaves the demo
        // account usable, so recovery needs no wait for the 60-second window to expire.
        const invalidForm = {
            email: `throttle-${locale}@example.test`,
            password: 'wrong-password',
        };
        try {
            const login = await send('GET', '/login');
            assert.equal(login.status(), 200);
            assert.equal(login.headers().location, undefined);
            assertHtml(login);
            assert.ok(
                (await login.text()).includes(publicOrigin),
                'Login page retains canonical HTTPS identity',
            );
            const sessionName = assertCookies();

            const invalid = await send('POST', '/login', invalidForm);
            assert.equal(invalid.status(), 302);
            assert.equal(invalid.headers().location, `${publicOrigin}/login`);
            assertHtml(invalid);
            assertCookies();
            for (let attempt = 2; attempt <= 5; attempt++) {
                const response = await send('POST', '/login', invalidForm);
                assert.equal(response.status(), 302);
                assert.equal(
                    response.headers().location,
                    `${publicOrigin}/login`,
                );
            }
            const limited = await send('POST', '/login', invalidForm);
            assert.equal(limited.status(), 429);
            assert.equal(limited.headers().location, undefined);
            assert.equal(limited.headers()['x-ratelimit-limit'], '5');
            assert.equal(limited.headers()['x-ratelimit-remaining'], '0');
            const retryAfter = Number(limited.headers()['retry-after']);
            assert.ok(
                Number.isInteger(retryAfter) &&
                    retryAfter > 0 &&
                    retryAfter <= 60,
            );
            assertHtml(limited);
            assert.ok(
                new RegExp(`<title>\\s*${throttleTitle}\\s*</title>`).test(
                    await limited.text(),
                ),
                `429 must use the ${locale} server error title`,
            );
            console.log(
                JSON.stringify({
                    locale,
                    server429Title: throttleTitle,
                    frontendAlertCopy: 'not asserted by raw HTTP',
                }),
            );

            cookies.delete(sessionName);
            const stale = await send('POST', '/login', invalidForm);
            assert.equal(stale.status(), 419);
            assert.equal(stale.headers().location, undefined);
            assertHtml(stale);
            assert.ok(
                new RegExp(`<title>\\s*${expiredTitle}\\s*</title>`).test(
                    await stale.text(),
                ),
                `419 must use the ${locale} server error title`,
            );
            console.log(
                JSON.stringify({
                    locale,
                    server419Title: expiredTitle,
                    localeResolution: 'resolved before CSRF rejection',
                    clientEmailAndIframeBehavior: 'retained dev proof only',
                }),
            );

            const refreshed = await send('GET', '/login');
            assert.equal(refreshed.status(), 200);
            assert.equal(refreshed.headers().location, undefined);
            assertHtml(refreshed);
            assertCookies();
            // Native API requests have no Fetch Metadata bypass; this proves the renewed CSRF token is usable.
            // A third address keeps the demo account's limiter count low for the later socket logins.
            const tokenProof = await send('POST', '/login', {
                email: `recovery-${locale}@example.test`,
                password: 'wrong-password',
            });
            assert.equal(tokenProof.status(), 302);
            assert.equal(
                tokenProof.headers().location,
                `${publicOrigin}/login`,
            );
            const manual = await send('POST', '/login', {
                email: 'test@example.test',
                password,
            });
            assert.equal(manual.status(), 302);
            assert.equal(manual.headers().location, `${publicOrigin}/sites`);
            assertCookies();
            const sites = await send('GET', '/sites');
            assert.equal(sites.status(), 200);
            assert.equal(sites.headers().location, undefined);
            assertHtml(sites);
            console.log(
                JSON.stringify({
                    locale,
                    recovery:
                        'explicit GET; usable CSRF token; separate manual login; authenticated sites200',
                    clientRendering: 'not asserted by raw HTTP',
                }),
            );
        } finally {
            await context.dispose();
        }
    });
}

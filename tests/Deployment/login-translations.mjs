// Render only responses from the disposable production nginx stack, including its built assets.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { chromium } from 'playwright';

const upstream = process.env.LOGIN_TRANSLATIONS_ORIGIN;
assert.ok(
    upstream,
    'Set LOGIN_TRANSLATIONS_ORIGIN to the production nginx proof URL',
);
const publicOrigin = 'https://monitor.example.com:8443';

for (const [locale, heading, subtitle] of [
    ['en', 'Sign in', 'to Heptapod'],
    ['de', 'Anmelden', 'bei Heptapod'],
]) {
    await test(`built production login resolves instance name in ${locale}`, async () => {
        const browser = await chromium.launch();
        let context;
        try {
            context = await browser.newContext();
            const errors = [];
            const page = await context.newPage();
            page.on('pageerror', (error) => errors.push(error.message));
            // The proof publishes HTTP on loopback; production generates canonical HTTPS URLs.
            // Forward those requests to real nginx, never to checkout assets or mocked responses.
            await context.route(`${publicOrigin}/**`, async (route) => {
                const url = new URL(route.request().url());
                const response = await route.fetch({
                    url: `${upstream}${url.pathname}${url.search}`,
                    headers: {
                        ...route.request().headers(),
                        host: 'monitor.example.com:8443',
                    },
                    maxRedirects: 0,
                });
                await route.fulfill({ response });
            });
            await context.addCookies([
                { name: 'locale', value: locale, url: publicOrigin },
            ]);

            const response = await page.goto(`${publicOrigin}/login`);
            assert.equal(response.status(), 200);
            await page.getByRole('heading', { level: 1 }).waitFor();
            const header = page
                .locator('header')
                .filter({ has: page.getByRole('heading', { level: 1 }) });
            assert.equal(await header.locator('p').textContent(), subtitle);
            assert.equal(await header.locator('h1').textContent(), heading);
            assert.deepEqual(errors, [], 'Production login JavaScript errors');
        } finally {
            // Closing the browser first disposes the request context that in-flight route.fetch calls still use.
            try {
                await context?.unrouteAll({ behavior: 'wait' });
            } finally {
                await browser.close();
            }
        }
    });
}

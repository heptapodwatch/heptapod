<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;

use function Amp\delay;

function waitForBrowserPath(PendingAwaitablePage|AwaitableWebpage $page, string $destination): void
{
    // Pest's Playwright waitForURL does not consume its request; blocking retry sleeps also starve the HTTP server.
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if ($page->script('location.pathname') === $destination) {
            return;
        }
        delay(0.1);
    }
    expect($page->script('location.pathname'))->toBe($destination);
}

function traceTodayNavigation(PendingAwaitablePage|AwaitableWebpage $page, string $selector, string $destination): void
{
    $page->script('window.__traceSelector = '.json_encode($selector));
    $page->script(<<<'JS'
        (() => {
            window.__inertiaEvents = [];
            const log = (type, data = {}) => window.__inertiaEvents.push({ at: performance.now(), type, ...data });
            for (const name of ['before', 'start', 'cancel', 'interrupted', 'exception', 'networkError', 'httpException', 'finish', 'navigate', 'success']) {
                document.addEventListener(`inertia:${name}`, event => log(name, {
                    url: event.detail?.visit?.url?.toString() ?? event.detail?.page?.url,
                    prefetch: event.detail?.visit?.prefetch,
                    only: event.detail?.visit?.only,
                    error: String(event.detail?.error ?? ''),
                }));
            }
            window.addEventListener('vite:preloadError', event => log('vite:preloadError', { error: String(event.payload ?? '') }));
            window.addEventListener('unhandledrejection', event => log('unhandledrejection', { error: String(event.reason ?? ''), stack: String(event.reason?.stack ?? '') }));
            new PerformanceObserver(list => {
                for (const entry of list.getEntries()) {
                    if (entry.initiatorType === 'xmlhttprequest' || entry.initiatorType === 'fetch') {
                        log('resource', { url: entry.name, duration: entry.duration, status: entry.responseStatus, bytes: entry.transferSize });
                    }
                }
            }).observe({ type: 'resource', buffered: true });
            const selector = window.__traceSelector;
            let downNode;
            document.addEventListener('pointerdown', event => {
                downNode = event.target.closest(selector);
                if (downNode) log('pointerdown', { href: downNode.href, connected: downNode.isConnected });
            }, true);
            document.addEventListener('pointerup', event => {
                const upNode = event.target.closest(selector);
                log('pointerup', { href: upNode?.href, same: upNode === downNode, downConnected: downNode?.isConnected });
            }, true);
            document.addEventListener('click', event => {
                log('click', { href: event.target.closest(selector)?.href, downConnected: downNode?.isConnected });
            }, true);
            new MutationObserver(() => {
                if (downNode && !downNode.isConnected) log('link-detached', { href: downNode.href });
            }).observe(document.body, { childList: true, subtree: true });
        })()
    JS);
    try {
        $page->click($selector);
        waitForBrowserPath($page, $destination);
    } catch (Throwable $error) {
        $page->wait(1);
        fwrite(STDERR, 'Navigation trace: '.json_encode($page->script('({ path: location.pathname, events: window.__inertiaEvents, resources: performance.getEntriesByType("resource").filter(e => e.name.includes(".js") || e.name.includes("/sites") || e.name.includes("/today/leave")).map(e => ({ name: e.name, duration: e.duration, status: e.responseStatus, bytes: e.transferSize })), console: window.__pestBrowser?.consoleLogs, errors: window.__pestBrowser?.jsErrors })'), JSON_PRETTY_PRINT)."\n");
        throw $error;
    }
}

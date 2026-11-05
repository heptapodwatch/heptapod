import puppeteer from 'puppeteer-core';
import { anonymizeProxy, closeAnonymizedProxy } from 'proxy-chain';

export async function launchBrowser(proxyUrl) {
    const localProxy = await anonymizeProxy({ url: proxyUrl, port: 0 });
    let browser;
    const close = async () => {
        try {
            if (browser) await browser.close();
        } finally {
            await closeAnonymizedProxy(localProxy, true);
        }
    };
    try {
        browser = await puppeteer.launch({
            executablePath: process.env.CHROME_PATH,
            headless: true,
            ignoreDefaultArgs: ['--disable-popup-blocking'],
            args: [
                ...process.env.CHROME_FLAGS.split(' '),
                `--proxy-server=${localProxy}`,
                '--proxy-bypass-list=<-loopback>',
                '--disable-quic',
                '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
            ],
        });
        return { browser, localProxy, close };
    } catch (error) {
        await close();
        throw error;
    }
}

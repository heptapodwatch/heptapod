// Native fixture client. This file is not copied into the Chromium image.
const endpoint = 'http://lighthouse-proxy-control:3129/scopes';
export async function createScope(policy) {
    const response = await fetch(endpoint, {
        method: 'POST',
        body: JSON.stringify(policy),
    });
    if (!response.ok) throw new Error('Proxy scope creation failed');
    const { token } = await response.json();
    if (typeof token !== 'string' || !/^[a-f0-9]{64}$/.test(token))
        throw new Error('Invalid proxy scope');
    return token;
}
export async function revokeScope(token) {
    const response = await fetch(`${endpoint}/${token}`, { method: 'DELETE' });
    if (response.status !== 204)
        throw new Error('Proxy scope revocation failed');
}

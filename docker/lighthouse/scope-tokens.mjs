import { lookup } from 'node:dns/promises';
import { createScope, revokeScope } from './proxy-scope.mjs';

if (process.argv[2] === 'create') {
    const tokens = {
        controlAddress: (
            await lookup('lighthouse-proxy-control', { family: 4 })
        ).address,
    };
    for (const scopeId of ['first', 'second'])
        tokens[scopeId] = await createScope({ siteHost: 'localhost' });
    process.stdout.write(JSON.stringify(tokens));
} else if (process.argv[2] === 'revoke') {
    let input = '';
    for await (const chunk of process.stdin) input += chunk;
    const tokens = JSON.parse(input);
    for (const scopeId of ['first', 'second'])
        await revokeScope(tokens[scopeId]);
} else {
    throw new Error('Expected create or revoke');
}

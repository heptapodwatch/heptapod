import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';

// Every string literal and template-literal head/tail in the TypeScript sources, for the unused-key report.
const root = process.argv[2] ?? process.cwd();
const literals = new Set();
const testLiterals = new Set();
const heads = new Set();
const tails = new Set();
function scan(directory) {
    if (!fs.existsSync(directory)) return;
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const file = path.join(directory, entry.name);
        if (entry.isDirectory()) {
            if (!['node_modules', 'vendor', '.git'].includes(entry.name))
                scan(file);
        } else if (/\.[cm]?[jt]sx?$/.test(entry.name)) {
            const target = /\.test\.[cm]?[jt]sx?$/.test(entry.name)
                ? testLiterals
                : literals;
            const source = ts.createSourceFile(
                file,
                fs.readFileSync(file, 'utf8'),
                ts.ScriptTarget.Latest,
                true,
            );
            function visit(node) {
                if (
                    ts.isStringLiteral(node) ||
                    ts.isNoSubstitutionTemplateLiteral(node)
                ) {
                    target.add(node.text);
                    const parent = node.parent;
                    if (
                        ts.isBinaryExpression(parent) &&
                        parent.operatorToken.kind === ts.SyntaxKind.PlusToken
                    )
                        (parent.left === node ? heads : tails).add(node.text);
                } else if (ts.isJsxText(node)) {
                    target.add(node.text.trim());
                } else if (ts.isTemplateExpression(node)) {
                    if (node.head.text !== '') heads.add(node.head.text);
                    const last = node.templateSpans.at(-1).literal.text;
                    if (last !== '') tails.add(last);
                }
                ts.forEachChild(node, visit);
            }
            visit(source);
        }
    }
}
scan(path.join(root, 'resources/js'));
console.log(
    JSON.stringify({
        literals: [...literals],
        testLiterals: [...testLiterals],
        heads: [...heads],
        tails: [...tails],
    }),
);

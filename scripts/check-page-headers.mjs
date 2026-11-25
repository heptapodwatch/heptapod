import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';

/*
 * One page header (heptapod-ui-rules Pages): every AppLayout page header is PageHeader, so header actions share one
 * placement. Reads the TSX syntax tree, so comments and strings (e.g. '[data-page-header]' selectors) never count:
 * - the data-page-header attribute, also inside an object literal spread into a tag ({...{ 'data-page-header': '' }}
 *   or a same-file variable holding one), only in page-header.tsx;
 * - a <header> element only in page-header.tsx and outside AppLayout: pages/auth/ (AuthLayout, app.tsx), pages that
 *   assign their own non-App `.layout`, and the named owners below.
 */
const marker = 'data-page-header';
const markerOwner = 'resources/js/components/page-header.tsx';
/** <header> elements inside AppLayout that are not page headers. */
const headerOwners = {
    'resources/js/components/page-header.tsx': 'The one page header.',
    'resources/js/components/app-mobile-header.tsx':
        'The 52 px mobile shell bar (banner landmark), not a page header.',
    'resources/js/pages/dev/components.tsx':
        'Sticky section bar of the local component catalog (/dev/components), outside the rollout.',
};

function filesIn(directory) {
    return fs
        .readdirSync(directory, { withFileTypes: true })
        .flatMap((entry) => {
            const file = path.join(directory, entry.name);
            if (entry.name === 'node_modules') return [];
            return entry.isDirectory()
                ? filesIn(file)
                : file.endsWith('.tsx') && !file.endsWith('.test.tsx')
                  ? [file]
                  : [];
        });
}

function unwrap(node) {
    while (
        node &&
        (ts.isParenthesizedExpression(node) ||
            ts.isAsExpression(node) ||
            ts.isSatisfiesExpression(node))
    )
        node = node.expression;
    return node;
}

function propertyName(property) {
    const name = property.name;
    return name && (ts.isIdentifier(name) || ts.isStringLiteral(name))
        ? name.text
        : undefined;
}

const failures = [];
const files = [
    ...filesIn('resources/js'),
    ...filesIn('addons').filter((file) => file.includes('/resources/')),
].sort((left, right) => left.localeCompare(right));
for (const file of files) {
    const source = ts.createSourceFile(
        file,
        fs.readFileSync(file, 'utf8'),
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TSX,
    );
    const line = (node) =>
        source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1;
    const variables = new Map();
    let ownLayout = false;
    function collect(node) {
        if (
            ts.isVariableDeclaration(node) &&
            ts.isIdentifier(node.name) &&
            node.initializer
        )
            variables.set(node.name.text, node.initializer);
        // Page.layout = [InvitationLayout]: a page that leaves AppLayout (app.tsx keeps an assigned layout).
        if (
            ts.isBinaryExpression(node) &&
            node.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
            ts.isPropertyAccessExpression(node.left) &&
            node.left.name.text === 'layout' &&
            !/\bAppLayout\b/.test(node.right.getText(source))
        )
            ownLayout = true;
        ts.forEachChild(node, collect);
    }
    collect(source);
    function spreadHasMarker(expression, seen = new Set()) {
        const node = unwrap(expression);
        if (node && ts.isObjectLiteralExpression(node))
            return node.properties.some((property) =>
                ts.isSpreadAssignment(property)
                    ? spreadHasMarker(property.expression, seen)
                    : propertyName(property) === marker,
            );
        if (node && ts.isIdentifier(node) && !seen.has(node.text))
            return spreadHasMarker(
                variables.get(node.text),
                new Set([...seen, node.text]),
            );
        return false;
    }
    const outsideAppLayout =
        file.startsWith('resources/js/pages/auth/') || ownLayout;
    function inspect(node) {
        if (ts.isJsxAttribute(node) && node.name.getText(source) === marker) {
            if (file !== markerOwner)
                failures.push(
                    `${file}:${line(node)}: hand-built page header (${marker}); render it through PageHeader`,
                );
        } else if (
            ts.isJsxSpreadAttribute(node) &&
            file !== markerOwner &&
            spreadHasMarker(node.expression)
        ) {
            failures.push(
                `${file}:${line(node)}: hand-built page header (${marker} through a spread); render it through PageHeader`,
            );
        } else if (
            (ts.isJsxOpeningElement(node) ||
                ts.isJsxSelfClosingElement(node)) &&
            node.tagName.getText(source) === 'header' &&
            !outsideAppLayout &&
            !(file in headerOwners)
        ) {
            failures.push(
                `${file}:${line(node)}: <header> element in an AppLayout page or component; render the page header through PageHeader`,
            );
        }
        ts.forEachChild(node, inspect);
    }
    inspect(source);
}
for (const [file, reason] of Object.entries(headerOwners))
    if (
        !fs.existsSync(file) ||
        !/<header\b/.test(fs.readFileSync(file, 'utf8'))
    )
        failures.push(
            `${file}: <header> exception no longer applies (${reason})`,
        );
if (failures.length) {
    console.error(`Page header guard failed:\n${failures.join('\n')}`);
    process.exit(1);
}
console.log(
    `Page header guard: ${files.length} files; no ${marker} or <header> outside PageHeader in AppLayout.`,
);

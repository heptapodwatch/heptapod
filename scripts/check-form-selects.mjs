import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';

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

const failures = [];
for (const file of [
    ...filesIn('resources/js'),
    ...filesIn('addons').filter((file) => file.includes('/resources/')),
].sort((left, right) => left.localeCompare(right))) {
    const source = ts.createSourceFile(
        file,
        fs.readFileSync(file, 'utf8'),
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TSX,
    );
    const arrays = new Map();
    function collect(node) {
        if (
            ts.isVariableDeclaration(node) &&
            ts.isIdentifier(node.name) &&
            node.initializer
        )
            arrays.set(node.name.text, node.initializer);
        ts.forEachChild(node, collect);
    }
    collect(source);
    function arrayLength(expression, seen = new Set()) {
        const node = unwrap(expression);
        if (node && ts.isArrayLiteralExpression(node))
            return node.elements.length;
        if (node && ts.isIdentifier(node) && !seen.has(node.text))
            return arrayLength(
                arrays.get(node.text),
                new Set([...seen, node.text]),
            );
        return Infinity;
    }
    function items(node) {
        if (
            ts.isJsxElement(node) &&
            node.openingElement.tagName.getText(source) === 'SelectTrigger'
        )
            return 0;
        if (
            ts.isJsxElement(node) &&
            node.openingElement.tagName.getText(source) === 'SelectItem'
        )
            return 1;
        if (ts.isJsxElement(node))
            return node.children.reduce(
                (count, child) => count + items(child),
                0,
            );
        if (ts.isJsxSelfClosingElement(node))
            return node.tagName.getText(source) === 'SelectItem' ? 1 : 0;
        if (
            ts.isCallExpression(node) &&
            ts.isPropertyAccessExpression(node.expression) &&
            node.expression.name.text === 'map'
        ) {
            let templateItems = 0;
            node.arguments.forEach((argument) => {
                templateItems += items(argument);
            });
            return templateItems
                ? arrayLength(node.expression.expression) * templateItems
                : Infinity;
        }
        let count = 0;
        ts.forEachChild(node, (child) => {
            count += items(child);
        });
        // Opaque JSX children may be props, imports or pre-rendered option arrays.
        return ts.isJsxExpression(node) && node.expression && count === 0
            ? Infinity
            : count;
    }
    function inspect(node) {
        if (
            ts.isJsxElement(node) &&
            node.openingElement.tagName.getText(source) === 'Select'
        ) {
            const count = items(node);
            let inFilterBar = false;
            for (let parent = node.parent; parent; parent = parent.parent) {
                if (
                    ts.isJsxElement(parent) &&
                    parent.openingElement.tagName.getText(source) ===
                        'FilterBar'
                )
                    inFilterBar = true;
            }
            let hasFilterTrigger = false;
            function findFilterTrigger(child) {
                if (
                    (ts.isJsxOpeningElement(child) ||
                        ts.isJsxSelfClosingElement(child)) &&
                    child.tagName.getText(source) === 'FilterSelectTrigger'
                )
                    hasFilterTrigger = true;
                ts.forEachChild(child, findFilterTrigger);
            }
            findFilterTrigger(node);
            const chartRange =
                file === 'resources/js/components/chart-area-interactive.tsx' &&
                node.getText(source).includes("aria-label={t('Time range')}");
            const exception = inFilterBar || hasFilterTrigger || chartRange;
            const line =
                source.getLineAndCharacterOfPosition(node.getStart(source))
                    .line + 1;
            const commentOf = (kind) =>
                node.children.some(
                    (child) =>
                        ts.isJsxExpression(child) &&
                        !child.expression &&
                        new RegExp(
                            `^\\{\\s*/\\*\\s*form-select: ${kind} \\S[\\s\\S]*?\\*/\\s*\\}$`,
                        ).test(child.getText(source)),
                );
            // The schema-enum exemption is only for the runtime enum-size
            // branch in schema-fields.tsx; anywhere else it does not exempt.
            const namedException =
                commentOf('schema-enum') &&
                file === 'resources/js/components/schema-fields.tsx';
            if (commentOf('schema-enum') && !namedException)
                failures.push(
                    `${file}:${line}: form-select: schema-enum is only allowed in schema-fields.tsx; use the searchable Combobox for data-driven lists`,
                );
            // Growable/data-driven lists belong on the searchable Combobox; a
            // data-driven comment no longer exempts a plain Select (L2 rule).
            if (commentOf('data-driven') && !exception)
                failures.push(
                    `${file}:${line}: data-driven form Select must use the searchable Combobox (TimezoneCombobox/EntityCombobox/FilterCombobox), not a plain Select`,
                );
            if (!Number.isFinite(count) && !exception && !namedException)
                failures.push(
                    `${file}:${line}: unresolved form Select options; use the searchable Combobox for data-driven lists or add a direct form-select: schema-enum comment naming the exception`,
                );
            // Toolbar Selects may only map over fixed registries or literal
            // option lists; mapping over props/server data is data-driven and
            // belongs on FilterCombobox.
            if (exception && !chartRange) {
                const toolbarAllowList = new Set([
                    'checkTypes',
                    'phases',
                    'statusLabels',
                ]);
                for (const match of node
                    .getText(source)
                    .matchAll(
                        /([A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*|Object\.(?:entries|keys|values)\([^)]*\)|\[[^\]]*\])\s*\.map\(/g,
                    )) {
                    const sourceExpr = match[1];
                    const root = sourceExpr.split('.')[0];
                    if (
                        !toolbarAllowList.has(root) &&
                        !sourceExpr.startsWith('Object.') &&
                        !sourceExpr.startsWith('[')
                    )
                        failures.push(
                            `${file}:${line}: toolbar Select over data (${sourceExpr}.map) must use FilterCombobox, not a plain Select`,
                        );
                }
            }
            if (count >= 1 && count <= 4 && !exception)
                failures.push(
                    `${file}:${line}: static form Select with ${count} options; use RadioGroup/RadioCards (or checkboxes for multi choice)`,
                );
            console.log(
                `${file}:${line}: ${Number.isFinite(count) ? count : 'data/schema-driven'} options${exception ? ' — toolbar/chart exception' : ''}`,
            );
        }
        ts.forEachChild(node, inspect);
    }
    inspect(source);
}
if (failures.length) {
    console.error(`Small-choice form guard failed:\n${failures.join('\n')}`);
    process.exit(1);
}
console.log('Small-choice form guard: no small static form Selects.');

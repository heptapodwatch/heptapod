import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';

const root = process.argv[2] ?? process.cwd();
const references = [];
let dynamic = 0;
function scan(directory) {
    if (!fs.existsSync(directory)) return;
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const file = path.join(directory, entry.name);
        if (entry.isDirectory()) {
            if (!['node_modules', 'vendor', '.git'].includes(entry.name))
                scan(file);
        } else if (/\.[cm]?[jt]sx?$/.test(entry.name)) {
            const source = ts.createSourceFile(
                file,
                fs.readFileSync(file, 'utf8'),
                ts.ScriptTarget.Latest,
                true,
            );
            const reference = (literal) =>
                references.push({
                    file: path.relative(root, file),
                    line:
                        source.getLineAndCharacterOfPosition(
                            literal.getStart(source),
                        ).line + 1,
                    key: literal.text,
                });
            // InputError translates its message: literal keys in its message branches count as t() keys.
            function messageLiterals(node) {
                if (ts.isStringLiteral(node)) reference(node);
                else if (
                    ts.isJsxExpression(node) ||
                    ts.isParenthesizedExpression(node)
                ) {
                    if (node.expression) messageLiterals(node.expression);
                } else if (ts.isConditionalExpression(node)) {
                    messageLiterals(node.whenTrue);
                    messageLiterals(node.whenFalse);
                } else if (
                    ts.isBinaryExpression(node) &&
                    node.operatorToken.kind ===
                        ts.SyntaxKind.QuestionQuestionToken
                )
                    messageLiterals(node.right);
            }
            function visit(node) {
                if (
                    ts.isJsxAttribute(node) &&
                    node.name.getText(source) === 'message' &&
                    node.initializer &&
                    node.parent.parent.tagName?.getText(source) === 'InputError'
                )
                    messageLiterals(node.initializer);
                if (ts.isCallExpression(node)) {
                    const callee = node.expression;
                    const name = ts.isIdentifier(callee)
                        ? callee.text
                        : ts.isPropertyAccessExpression(callee)
                          ? callee.name.text
                          : null;
                    if (['t', '__', 'trans'].includes(name)) {
                        const key = node.arguments[0];
                        if (key && ts.isStringLiteral(key)) {
                            reference(key);
                        } else {
                            dynamic++;
                        }
                    }
                }
                ts.forEachChild(node, visit);
            }
            visit(source);
        }
    }
}
for (const directory of ['resources/js', 'app', 'addons'])
    scan(path.join(root, directory));
console.log(JSON.stringify({ references, dynamic }));

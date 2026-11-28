// Credit: https://usehooks-ts.com/
import { useEffect, useState } from 'react';

/** Copies text, or text the server is still producing; false when the browser refuses the clipboard. */
export type CopyFn = (text: string | Promise<string>) => Promise<boolean>;

/** Whether the last copy succeeded (reset after 2 s, so a repeated copy confirms again) and the copy function. */
export function useClipboard(): [boolean, CopyFn] {
    const [copied, setCopied] = useState(false);
    useEffect(() => {
        if (!copied) return;
        const timer = window.setTimeout(() => setCopied(false), 2000);
        return () => window.clearTimeout(timer);
    }, [copied]);

    const copy: CopyFn = async (text) => {
        setCopied(false);
        if (!navigator?.clipboard) {
            return false;
        }

        try {
            if (typeof text === 'string') {
                await navigator.clipboard.writeText(text);
            } else {
                // A promised ClipboardItem keeps the click's user activation (Safari) while the server answers.
                await navigator.clipboard.write([
                    new ClipboardItem({
                        'text/plain': text.then(
                            (value) =>
                                new Blob([value], { type: 'text/plain' }),
                        ),
                    }),
                ]);
            }
            setCopied(true);

            return true;
        } catch {
            // Permission/unavailable clipboard is an expected boundary failure; callers show manual-copy guidance.
            return false;
        }
    };

    return [copied, copy];
}

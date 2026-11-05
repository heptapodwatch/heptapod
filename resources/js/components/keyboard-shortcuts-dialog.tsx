import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { SectionHeading } from '@/components/section-heading';
import { Kbd, KbdGroup } from '@/components/ui/kbd';
import type { Shortcut, ShortcutGroup } from '@/lib/shortcuts';

const groups: ShortcutGroup[] = ['General', 'Navigation', 'Lists'];

export function ShortcutKeys({ keys }: { keys: string[] }) {
    const { t } = useTranslation();
    return (
        <KbdGroup>
            {keys.map((key, index) => (
                <Fragment key={key}>
                    {index > 0 && (
                        <span className="text-xs text-muted-foreground">
                            {t('then')}
                        </span>
                    )}
                    <Kbd>{key}</Kbd>
                </Fragment>
            ))}
        </KbdGroup>
    );
}

export function KeyboardShortcutsDialog({
    open,
    onOpenChange,
    shortcuts,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shortcuts: Shortcut[];
}) {
    const { t } = useTranslation();
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[min(640px,90dvh)] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{t('Keyboard shortcuts')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Single-key shortcuts work when no text field has focus.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                {groups.map((group) => (
                    <section key={group} aria-labelledby={`shortcuts-${group}`}>
                        <SectionHeading
                            level={3}
                            id={`shortcuts-${group}`}
                            className="pb-1"
                        >
                            {t(group)}
                        </SectionHeading>
                        <dl className="divide-y">
                            {shortcuts
                                .filter((shortcut) => shortcut.group === group)
                                .map((shortcut) => (
                                    <div
                                        key={shortcut.keys.join(' ')}
                                        className="flex min-h-9 items-center justify-between gap-4 text-sm"
                                    >
                                        <dt>
                                            {t(shortcut.label, {
                                                page: shortcut.values?.page
                                                    ? t(shortcut.values.page)
                                                    : undefined,
                                            })}
                                        </dt>
                                        <dd>
                                            <ShortcutKeys
                                                keys={shortcut.keys}
                                            />
                                        </dd>
                                    </div>
                                ))}
                        </dl>
                    </section>
                ))}
            </DialogContent>
        </Dialog>
    );
}

import { cn } from '@/lib/utils';
import { listRowPadding } from '@/components/ui/table';
import { useTranslation } from 'react-i18next';
import { KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import type { Passkey } from '@/types/auth';

type Props = {
    passkey: Passkey;
    /** Resolves true when the passkey was removed. */
    onDelete: (id: number) => Promise<boolean>;
};

export default function PasskeyItem({ passkey, onDelete }: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <div
            className={cn(
                'flex items-center justify-between border-b last:border-b-0',
                listRowPadding,
            )}
        >
            <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                    <KeyRound className="h-5 w-5 text-muted-foreground" />
                </div>
                <div className="space-y-1">
                    <div className="flex items-center gap-2.5">
                        <p className="font-medium tracking-tight">
                            {passkey.name}
                        </p>
                        {passkey.authenticator && (
                            <Badge variant="outline">
                                {passkey.authenticator}
                            </Badge>
                        )}
                    </div>
                    {(passkey.created_at_diff || passkey.last_used_at_diff) && (
                        <p className="text-sm text-muted-foreground">
                            {passkey.created_at_diff && (
                                <>
                                    {t('Added')} {passkey.created_at_diff}
                                </>
                            )}
                            {passkey.last_used_at_diff && (
                                <>
                                    {passkey.created_at_diff && (
                                        <span className="mx-1 text-muted-foreground/50">
                                            /
                                        </span>
                                    )}
                                    {t('Last used')} {passkey.last_used_at_diff}
                                </>
                            )}
                        </p>
                    )}
                </div>
            </div>

            <Button
                variant="ghost"
                size="sm"
                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                onClick={() => setOpen(true)}
            >
                <Trash2 className="h-4 w-4" />
                <span className="sr-only">{t('Remove')}</span>
            </Button>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                destructive
                title={t('Remove passkey')}
                description={t(
                    'Remove this passkey? It cannot be used to sign in after removal.',
                )}
                confirmLabel={t('Remove passkey')}
                onConfirm={() => onDelete(passkey.id)}
            >
                <p className="font-mono text-sm">{passkey.name}</p>
            </ConfirmDialog>
        </div>
    );
}

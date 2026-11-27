import { Head, Link, useForm, usePoll } from '@inertiajs/react';
import { Plus, Server } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '@/components/empty-state';
import {
    EntityLink,
    openRowTarget,
    rowTargetClass,
} from '@/components/entity-link';
import { FormSecondaryAction } from '@/components/form-section';
import InputError from '@/components/input-error';
import { MonitoringTime } from '@/components/monitoring';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { PendingChanges } from '@/components/pending-changes';
import {
    ServerGaugeCells,
    ServerGaugeLine,
    ServerStateBadge,
    useServerSize,
} from '@/components/server-values';
import { SheetForm } from '@/components/sheet-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet } from '@/components/ui/sheet';
import {
    listCardPadding,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useFormat } from '@/hooks/use-format';
import { cn } from '@/lib/utils';
import { show, store } from '@/routes/servers';

type ServerRow = Heptapod.Addons.Servers.Data.ServerRowData;

function AddServerSheet({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation();
    const form = useForm({ name: '' });
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <SheetForm
                title={t('servers.add.title')}
                description={t('servers.add.description')}
                aria-busy={form.processing}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(store.url());
                }}
                footer={
                    <>
                        <FormSecondaryAction
                            disabled={form.processing}
                            onClick={onClose}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button type="submit" disabled={form.processing}>
                            {t('servers.add.submit')}
                        </Button>
                    </>
                }
            >
                <div className="space-y-1.5">
                    <Label htmlFor="server-name">{t('Name')}</Label>
                    <Input
                        id="server-name"
                        name="name"
                        required
                        maxLength={120}
                        value={form.data.name}
                        disabled={form.processing}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        aria-describedby={
                            form.errors.name ? 'server-name-error' : undefined
                        }
                    />
                    <InputError
                        id="server-name-error"
                        message={form.errors.name}
                    />
                </div>
            </SheetForm>
        </Sheet>
    );
}

export default function ServersIndex({
    servers,
    thresholds,
}: Heptapod.Addons.Servers.Data.ServersPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const size = useServerSize();
    const [adding, setAdding] = useState(false);
    // New reports arrive every minute: values refresh in place, servers another tab added or removed wait behind
    // "N new changes".
    usePoll(15_000, { only: ['servers'] });
    const buffered = useBufferedChanges(servers, (server) => server.id);
    const reboot = (server: ServerRow) =>
        server.values?.reboot_required === true && (
            <span className="text-xs text-status-warn">
                {t('servers.fact.reboot_required')}
            </span>
        );
    const addButton = (
        <Button onClick={() => setAdding(true)}>
            <Plus className="size-4" />
            {t('servers.add.action')}
        </Button>
    );
    return (
        <>
            <Head title={t('servers.title')} />
            <PageContainer>
                <PageHeader
                    title={t('servers.title')}
                    description={t('servers.list.description')}
                    count={format.number(buffered.rows.length)}
                    badge={
                        <Badge variant="outline">
                            {t('servers.experimental')}
                        </Badge>
                    }
                    actions={addButton}
                />
                <PendingChanges
                    count={buffered.pendingCount}
                    apply={buffered.apply}
                />
                {buffered.rows.length === 0 ? (
                    <EmptyState
                        icon={Server}
                        title={t('servers.list.empty_title')}
                        description={t('servers.list.empty_description')}
                        action={addButton}
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border">
                        <div className="hidden md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('servers.column.server')}
                                        </TableHead>
                                        <TableHead>{t('Status')}</TableHead>
                                        <TableHead className="text-center">
                                            {t('servers.column.disk')}
                                        </TableHead>
                                        <TableHead className="text-center">
                                            {t('servers.column.memory')}
                                        </TableHead>
                                        <TableHead className="text-center">
                                            {t('servers.column.load')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('servers.column.last_report')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {buffered.rows.map((server) => (
                                        <TableRow
                                            key={server.id}
                                            className={rowTargetClass}
                                            onClick={openRowTarget(
                                                show.url(server.id),
                                            )}
                                        >
                                            <TableCell column="name">
                                                <EntityLink
                                                    name={server.name}
                                                    href={show.url(server.id)}
                                                />
                                                {server.values && (
                                                    <span className="block font-mono text-xs text-muted-foreground tabular-nums">
                                                        {size(server.values)}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <span className="flex flex-col items-start gap-1">
                                                    <ServerStateBadge
                                                        server={server}
                                                    />
                                                    {reboot(server)}
                                                </span>
                                            </TableCell>
                                            <ServerGaugeCells
                                                values={server.values}
                                                thresholds={thresholds}
                                            />
                                            <TableCell className="text-right">
                                                {server.last_report_at && (
                                                    <MonitoringTime
                                                        value={
                                                            server.last_report_at
                                                        }
                                                        className="font-mono text-xs tabular-nums"
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <ul className="divide-y md:hidden">
                            {buffered.rows.map((server) => (
                                <li key={server.id}>
                                    <Link
                                        href={show.url(server.id)}
                                        className={cn(
                                            'block space-y-2',
                                            listCardPadding,
                                            rowTargetClass,
                                        )}
                                    >
                                        <span className="flex items-start justify-between gap-3">
                                            <span className="min-w-0">
                                                <EntityLink
                                                    name={server.name}
                                                    insideLink
                                                />
                                                {server.values && (
                                                    <span className="block font-mono text-xs text-muted-foreground tabular-nums">
                                                        {size(server.values)}
                                                    </span>
                                                )}
                                            </span>
                                            <ServerStateBadge server={server} />
                                        </span>
                                        {reboot(server)}
                                        {server.values && (
                                            <ServerGaugeLine
                                                values={server.values}
                                                thresholds={thresholds}
                                            />
                                        )}
                                        {server.last_report_at && (
                                            <span className="block text-right font-mono text-xs text-muted-foreground tabular-nums">
                                                {
                                                    format.monitorTime(
                                                        server.last_report_at,
                                                    ).label
                                                }
                                            </span>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </PageContainer>
            {adding && <AddServerSheet onClose={() => setAdding(false)} />}
        </>
    );
}

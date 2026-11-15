import { Plus, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type ReplicatorColumn<Key extends string> = {
    key: Key;
    label: string;
    placeholder?: string;
    type?: 'text' | 'email' | 'url';
    /** Geist Mono, for URLs and addresses. */
    mono?: boolean;
    /** A fixed narrow column (a label); the other columns share the rest. */
    narrow?: boolean;
};

/**
 * A short list of rows of one or more text fields inside a form (heptapod-ui-rules § Forms): rows are added with Add,
 * removed with their trailing ×; Enter in a row's last field adds the next row, Backspace in an empty row removes it.
 */
export function ReplicatorField<Key extends string>({
    id,
    label,
    columns,
    rows,
    onRowsChange,
    max,
    addLabel,
    rowName,
    empty,
    error,
    disabled = false,
}: {
    id: string;
    label: string;
    columns: ReplicatorColumn<Key>[];
    rows: Record<Key, string>[];
    onRowsChange: (rows: Record<Key, string>[]) => void;
    max: number;
    addLabel: string;
    /** The row's accessible name, e.g. "Repository 2"; `index` starts at 0. */
    rowName: (index: number) => string;
    /** One sentence shown while there is no row. */
    empty: string;
    /** The message of one cell (server errors such as `repositories.2.url`). */
    error: (index: number, key: Key) => string | undefined;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
    const labelId = useId();
    const addRef = useRef<HTMLButtonElement>(null);
    const [focusTarget, setFocusTarget] = useState<string | null>(null);
    const canAdd = !disabled && rows.length < max;
    const cellId = (index: number, key: Key) => `${id}-${index}-${key}`;
    const first = columns[0]!.key;

    useEffect(() => {
        if (focusTarget === null) return;
        (focusTarget === 'add'
            ? addRef.current
            : document.getElementById(focusTarget)
        )?.focus();
        setFocusTarget(null);
    }, [focusTarget]);

    const add = () => {
        if (!canAdd) return;
        onRowsChange([
            ...rows,
            Object.fromEntries(
                columns.map((column) => [column.key, '']),
            ) as Record<Key, string>,
        ]);
        setFocusTarget(cellId(rows.length, first));
    };
    // Focus goes to the row that takes the removed row's place, else the previous row, else Add.
    const remove = (index: number) => {
        onRowsChange(rows.filter((_, at) => at !== index));
        const neighbour =
            index < rows.length - 1 ? index : index > 0 ? index - 1 : null;
        setFocusTarget(neighbour === null ? 'add' : cellId(neighbour, first));
    };
    const keyDown = (
        event: KeyboardEvent<HTMLInputElement>,
        index: number,
        position: number,
    ) => {
        // Enter and Backspace while an input method composes text belong to the composition.
        if (event.nativeEvent.isComposing) return;
        if (event.key === 'Enter') {
            // Enter never submits the surrounding form from a row.
            event.preventDefault();
            if (position < columns.length - 1) {
                setFocusTarget(cellId(index, columns[position + 1]!.key));
            } else if (index < rows.length - 1) {
                setFocusTarget(cellId(index + 1, first));
            } else {
                add();
            }
        }
        if (
            event.key === 'Backspace' &&
            position === 0 &&
            columns.every((column) => rows[index]![column.key] === '')
        ) {
            event.preventDefault();
            remove(index);
        }
    };

    return (
        <div
            role="group"
            aria-labelledby={labelId}
            className="@container/replicator space-y-2"
        >
            <Label id={labelId}>{label}</Label>
            {rows.length === 0 ? (
                <p className="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <>
                    {/* Column names for sighted users; every input carries "<column>, <row>" as its own name. */}
                    <div
                        aria-hidden="true"
                        className="hidden gap-2 pr-10 @lg/replicator:flex"
                    >
                        {columns.map((column) => (
                            <span
                                key={column.key}
                                className={cn(
                                    'text-xs text-muted-foreground',
                                    column.narrow ? 'w-44' : 'flex-1',
                                )}
                            >
                                {column.label}
                            </span>
                        ))}
                    </div>
                    <ul className="space-y-2">
                        {rows.map((row, index) => (
                            <li key={index} className="flex items-start gap-2">
                                <div className="flex min-w-0 flex-1 flex-col gap-2 @lg/replicator:flex-row">
                                    {columns.map((column, position) => {
                                        const inputId = cellId(
                                            index,
                                            column.key,
                                        );
                                        const message = error(
                                            index,
                                            column.key,
                                        );
                                        return (
                                            <div
                                                key={column.key}
                                                className={cn(
                                                    'min-w-0 space-y-1',
                                                    column.narrow
                                                        ? '@lg/replicator:w-44'
                                                        : 'flex-1',
                                                )}
                                            >
                                                <Input
                                                    id={inputId}
                                                    type={column.type ?? 'text'}
                                                    aria-label={`${column.label}, ${rowName(index)}`}
                                                    placeholder={
                                                        column.placeholder
                                                    }
                                                    value={row[column.key]}
                                                    disabled={disabled}
                                                    className={cn(
                                                        column.mono &&
                                                            'font-mono',
                                                    )}
                                                    onChange={(event) =>
                                                        onRowsChange(
                                                            rows.map(
                                                                (other, at) =>
                                                                    at === index
                                                                        ? {
                                                                              ...other,
                                                                              [column.key]:
                                                                                  event
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : other,
                                                            ),
                                                        )
                                                    }
                                                    onKeyDown={(event) =>
                                                        keyDown(
                                                            event,
                                                            index,
                                                            position,
                                                        )
                                                    }
                                                    {...fieldErrorProps(
                                                        inputId,
                                                        message,
                                                    )}
                                                />
                                                <InputError
                                                    message={message}
                                                    field={inputId}
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    disabled={disabled}
                                    aria-label={t('replicator.remove', {
                                        name: rowName(index),
                                    })}
                                    onClick={() => remove(index)}
                                >
                                    <X aria-hidden="true" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    ref={addRef}
                    type="button"
                    variant="ghost"
                    disabled={!canAdd}
                    onClick={add}
                >
                    <Plus aria-hidden="true" />
                    {addLabel}
                </Button>
                {rows.length < max ? (
                    <span className="font-mono text-xs text-muted-foreground tabular-nums">
                        {rows.length} / {max}
                    </span>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        {t('replicator.max_reached', {
                            count: max,
                            formattedCount: max,
                        })}
                    </span>
                )}
            </div>
        </div>
    );
}

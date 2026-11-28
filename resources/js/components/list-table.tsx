import { Link } from '@inertiajs/react';
import type { KeyboardEventHandler, ReactNode, Ref } from 'react';
import { useTranslation } from 'react-i18next';
import { openRowTarget, rowTargetClass } from '@/components/entity-link';
import { FilterSelectTrigger } from '@/components/filter-bar';
import { ListFooter } from '@/components/list-footer';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import {
    listCardPadding,
    SortableTableHead,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/** A server sort (`?sort=`): `key` orders the shown values in `firstDirection`; `-key` reverses it. */
export type ListSort = {
    key: string;
    firstDirection?: 'ascending' | 'descending';
};

export type ListColumn<Row> = {
    id: string;
    header: string;
    /** The full name where `header` abbreviates it: the header's tooltip and accessible name. */
    title?: string;
    sort?: ListSort;
    align?: 'start' | 'center' | 'end';
    /** Width and visibility classes, shared by the header and the cells. */
    className?: string;
    /** TableCell's content role for unbounded text. */
    content?: 'name' | 'primary' | 'code';
    cell: (row: Row) => ReactNode;
};

/**
 * The list's container width from which the table shows; below it the rows are cards and the FilterBar offers Sort. The
 * FilterBar and the table are both `@container/list` of the page's width, so the same classes switch both.
 */
export const listBreakpoints = {
    sm: { table: 'hidden @[600px]/list:block', cards: '@[600px]/list:hidden' },
    xl: {
        table: 'hidden @[960px]/list:block',
        cards: '@[960px]/list:hidden',
    },
} as const;
export type ListBreakpoint = keyof typeof listBreakpoints;

const alignClass = {
    start: undefined,
    center: 'text-center',
    end: 'text-right',
};

/**
 * The one data table of a page list: a header per column (sortable ones through SortableTableHead), one row target per
 * row, cards below the breakpoint, the page's empty state when there are no rows and the shared ListFooter while there are.
 */
export function ListTable<Row>({
    rows,
    rowKey,
    rowHref,
    rowClassName,
    rowProps,
    columns,
    card,
    breakpoint = 'sm',
    tableClassName,
    sort,
    onSort,
    columnsMenu,
    empty,
    count,
    pagination,
    footerExtra,
    ref,
    onKeyDown,
}: {
    rows: Row[];
    rowKey: (row: Row) => string | number;
    rowHref: (row: Row) => string;
    rowClassName?: (row: Row) => string | undefined;
    /** Attributes for both the table row and the card link (data attributes, a label, a prefetch on hover). */
    rowProps?: (row: Row) => Record<`data-${string}`, boolean> & {
        'aria-label'?: string;
        onMouseEnter?: () => void;
    };
    columns: ListColumn<Row>[];
    /** The card's content below the breakpoint; the card links to the row's href. */
    card: (row: Row) => ReactNode;
    breakpoint?: ListBreakpoint;
    tableClassName?: string;
    sort?: string;
    onSort?: (sort: string) => void;
    /** A column choice: its own trailing header cell, and above the cards. */
    columnsMenu?: ReactNode;
    empty: ReactNode;
    /** The ListFooter's count, page and list action below the rows; an empty list shows only `empty`. */
    count: ReactNode;
    pagination?: Heptapod.Contracts.PaginationData;
    footerExtra?: ReactNode;
    ref?: Ref<HTMLDivElement>;
    onKeyDown?: KeyboardEventHandler<HTMLDivElement>;
}) {
    const classes = listBreakpoints[breakpoint];
    return (
        <>
            {rows.length === 0 ? (
                empty
            ) : (
                <div
                    ref={ref}
                    className="@container/list relative"
                    onKeyDown={onKeyDown}
                >
                    <div
                        className={cn(
                            'overflow-hidden rounded-lg border',
                            classes.table,
                        )}
                    >
                        <Table className={tableClassName}>
                            <TableHeader>
                                <TableRow>
                                    {columns.map((column) =>
                                        column.sort &&
                                        sort !== undefined &&
                                        onSort ? (
                                            <SortableTableHead
                                                key={column.id}
                                                sortKey={column.sort.key}
                                                sort={sort}
                                                onSort={onSort}
                                                firstDirection={
                                                    column.sort.firstDirection
                                                }
                                                justify={column.align}
                                                className={cn(
                                                    alignClass[
                                                        column.align ?? 'start'
                                                    ],
                                                    column.className,
                                                )}
                                            >
                                                {column.header}
                                            </SortableTableHead>
                                        ) : (
                                            <TableHead
                                                key={column.id}
                                                className={cn(
                                                    alignClass[
                                                        column.align ?? 'start'
                                                    ],
                                                    column.className,
                                                )}
                                            >
                                                <ColumnHeader column={column} />
                                            </TableHead>
                                        ),
                                    )}
                                    {columnsMenu && (
                                        // Its own cell, so hiding any column never hides the menu.
                                        <TableHead className="w-10 px-1 text-right">
                                            {columnsMenu}
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow
                                        key={rowKey(row)}
                                        data-flip-key={`row:${rowKey(row)}`}
                                        className={cn(
                                            rowTargetClass,
                                            rowClassName?.(row),
                                        )}
                                        onClick={openRowTarget(rowHref(row))}
                                        {...rowProps?.(row)}
                                    >
                                        {columns.map((column) => (
                                            <TableCell
                                                key={column.id}
                                                column={column.content}
                                                className={cn(
                                                    'overflow-hidden',
                                                    alignClass[
                                                        column.align ?? 'start'
                                                    ],
                                                    column.className,
                                                )}
                                            >
                                                {column.cell(row)}
                                            </TableCell>
                                        ))}
                                        {columnsMenu && (
                                            <TableCell className="w-10 px-1" />
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    {columnsMenu && (
                        // The cards have no header row, so the column choice sits above them.
                        <div
                            className={cn(
                                '-mt-1 mb-1 flex justify-end',
                                classes.cards,
                            )}
                        >
                            {columnsMenu}
                        </div>
                    )}
                    <ul
                        className={cn(
                            'divide-y overflow-hidden rounded-lg border',
                            classes.cards,
                        )}
                    >
                        {rows.map((row) => (
                            <li
                                key={rowKey(row)}
                                data-flip-key={`card:${rowKey(row)}`}
                            >
                                <Link
                                    href={rowHref(row)}
                                    prefetch
                                    className={cn(
                                        'flex min-w-0 flex-col gap-2',
                                        listCardPadding,
                                        rowTargetClass,
                                        rowClassName?.(row),
                                    )}
                                    {...rowProps?.(row)}
                                >
                                    {card(row)}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {rows.length > 0 && (
                <ListFooter
                    count={count}
                    pagination={pagination}
                    extra={footerExtra}
                />
            )}
        </>
    );
}

function ColumnHeader<Row>({ column }: { column: ListColumn<Row> }) {
    if (!column.title) return column.header;
    return (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span aria-hidden="true">{column.header}</span>
                </TooltipTrigger>
                <TooltipContent>{column.title}</TooltipContent>
            </Tooltip>
            <span className="sr-only">{column.title}</span>
        </>
    );
}

/**
 * The FilterBar's Sort below the table breakpoint, where the cards have no sortable headers: every server sort of the
 * table's columns and its reverse, writing the same `?sort=` as the headers.
 */
export function ListSortSelect({
    columns,
    sort,
    onSort,
    breakpoint = 'sm',
}: {
    columns: Pick<ListColumn<never>, 'header' | 'title' | 'sort'>[];
    sort: string;
    onSort: (sort: string) => void;
    breakpoint?: ListBreakpoint;
}) {
    const { t } = useTranslation();
    const sortOptions = columns.flatMap(
        ({ header, title, sort: columnSort }) => {
            if (!columnSort) return [];
            const first = columnSort.firstDirection ?? 'ascending';
            const reversed = first === 'ascending' ? 'descending' : 'ascending';
            const column = title ?? header;
            const label = (direction: 'ascending' | 'descending') =>
                direction === 'ascending'
                    ? t('list.sort.ascending', { column })
                    : t('list.sort.descending', { column });
            return [
                { value: columnSort.key, label: label(first) },
                { value: `-${columnSort.key}`, label: label(reversed) },
            ];
        },
    );
    return (
        <div
            className={cn(
                'order-1 flex min-w-0 flex-1 md:order-none md:flex-none',
                listBreakpoints[breakpoint].cards,
            )}
        >
            <Select value={sort} onValueChange={onSort}>
                <FilterSelectTrigger
                    aria-label={t('list.sort.label')}
                    onClear={null}
                >
                    <SelectValue />
                </FilterSelectTrigger>
                <SelectContent>
                    {sortOptions.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

import * as React from "react"
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import type { SiteState } from "@/types/monitoring"
import { RowHintProvider, useRowHints } from "@/components/truncated-text"

export const tableHeaderBackground = "bg-muted"
export const tableHeaderText = "text-label font-label text-foreground-faint"
export const tableHorizontalPadding = "px-4 @[900px]/table:px-3"
export const tableHeaderPadding = cn(tableHorizontalPadding, "h-[34px]")
const rowText = "text-cell [&_[data-entity-name]]:text-cell"
export const tableRowHeight = "h-9"
/**
 * The one emphasis for rows that need action now: a 4 px inset bar that composes with the inset focus ring. Its tone
 * follows the row's own state: Down and Failed share the destructive bar, Check error the neutral one (open, but never a
 * Down claim). No list emphasises Warning rows; OK, resolved and every other state get none.
 */
const rowEmphasisByState: Partial<Record<SiteState, string>> = {
  down: "shadow-[inset_4px_0_0_var(--status-down)]",
  failed: "shadow-[inset_4px_0_0_var(--status-down)]",
  "check-error": "shadow-[inset_4px_0_0_var(--status-error)]",
}
export function rowEmphasisClass(state: SiteState): string | undefined {
  return rowEmphasisByState[state]
}
const tableCellPadding = cn(tableHorizontalPadding, "py-1")
export const listCardPadding = cn(tableHorizontalPadding, "min-h-10 py-2", rowText)
export const listRowPadding = cn(listCardPadding, "sm:min-h-9 sm:py-1")
export const tableRowStyle = "border-b border-border-soft transition-colors hover:bg-accent has-aria-expanded:bg-accent data-[state=selected]:bg-primary-soft"

function ListHeader({ className, ...props }: React.ComponentProps<"div">) {
  return <div className={cn("hidden items-center gap-4", tableHeaderBackground, tableHeaderText, tableHeaderPadding, tableRowStyle, className)} {...props} />
}

/** Rows own the keyboard hint tooltip: focusing the row's link lists the row's TooltipHint/TruncatedText values. */
function ListRow({ as = "div", className, onFocus, onBlur, onKeyDown, onPointerDown, ...props }: React.ComponentProps<"div"> & { as?: "div" | "article" }) {
  const Component = as
  const hints = useRowHints()
  return (
    <RowHintProvider value={hints.context}>
      <Component
        data-slot="list-row"
        className={cn("grid min-w-0 gap-4 last:border-b-0", listRowPadding, tableRowStyle, className)}
        onFocus={(event: React.FocusEvent<HTMLDivElement>) => { onFocus?.(event); hints.handlers.onFocus(event) }}
        onBlur={(event: React.FocusEvent<HTMLDivElement>) => { onBlur?.(event); hints.handlers.onBlur() }}
        onKeyDown={(event: React.KeyboardEvent<HTMLDivElement>) => { onKeyDown?.(event); hints.handlers.onKeyDown(event) }}
        onPointerDown={(event: React.PointerEvent<HTMLDivElement>) => { onPointerDown?.(event); hints.handlers.onPointerDown() }}
        {...props}
      />
      {hints.tooltip}
    </RowHintProvider>
  )
}

function Table({ className, ...props }: React.ComponentProps<"table">) {
  return (
    <div
      data-slot="table-container"
      className="@container/table relative w-full overflow-x-auto"
    >
      <table
        data-slot="table"
        className={cn("w-full caption-bottom", rowText, className)}
        {...props}
      />
    </div>
  )
}

function TableHeader({ className, ...props }: React.ComponentProps<"thead">) {
  return (
    <thead
      data-slot="table-header"
      // The header row is not a row target: no row hover band under its sortable headers.
      className={cn("[&_tr]:border-b [&_tr]:hover:bg-transparent", tableHeaderBackground, tableHeaderText, className)}
      {...props}
    />
  )
}

function TableBody({ className, ...props }: React.ComponentProps<"tbody">) {
  return (
    <tbody
      data-slot="table-body"
      className={cn("[&_tr:last-child]:border-0", className)}
      {...props}
    />
  )
}

function TableFooter({ className, ...props }: React.ComponentProps<"tfoot">) {
  return (
    <tfoot
      data-slot="table-footer"
      className={cn(
        "border-t bg-muted/50 font-medium [&>tr]:last:border-b-0",
        className
      )}
      {...props}
    />
  )
}

function TableRow({ className, onFocus, onBlur, onKeyDown, onPointerDown, ...props }: React.ComponentProps<"tr">) {
  const hints = useRowHints()
  return (
    <RowHintProvider value={hints.context}>
      <tr
        data-slot="table-row"
        className={cn(
          tableRowHeight,
          tableRowStyle,
          className
        )}
        onFocus={(event) => { onFocus?.(event); hints.handlers.onFocus(event) }}
        onBlur={(event) => { onBlur?.(event); hints.handlers.onBlur() }}
        onKeyDown={(event) => { onKeyDown?.(event); hints.handlers.onKeyDown(event) }}
        onPointerDown={(event) => { onPointerDown?.(event); hints.handlers.onPointerDown() }}
        {...props}
      />
      {hints.tooltip}
    </RowHintProvider>
  )
}

function TableHead({ className, ...props }: React.ComponentProps<"th">) {
  return (
    <th
      data-slot="table-head"
      className={cn(
        "text-left align-middle whitespace-nowrap [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]",
        tableHeaderPadding,
        tableHeaderText,
        className
      )}
      {...props}
    />
  )
}

/**
 * A header the server sorts by (`?sort=`), as the Sites table's: `sortKey` sorts, a second click sends `-sortKey` to reverse it.
 * `firstDirection` is how `sortKey` orders the shown values (a "highest first" column is descending), so `aria-sort` names
 * the real direction. `justify` follows the column's text alignment.
 */
function SortableTableHead({ sortKey, sort, onSort, firstDirection = "ascending", justify = "start", className, children, ...props }: React.ComponentProps<"th"> & { sortKey: string; sort: string; onSort: (sort: string) => void; firstDirection?: "ascending" | "descending"; justify?: "start" | "center" | "end" }) {
  const reversed = firstDirection === "ascending" ? "descending" : "ascending"
  const direction = sort === sortKey ? firstDirection : sort === `-${sortKey}` ? reversed : "none"
  return (
    <TableHead aria-sort={direction} className={className} {...props}>
      <SortHeaderButton
        direction={direction}
        className={{ start: "-ml-3 @[900px]/table:-ml-1", center: "", end: "-mr-3 @[900px]/table:-mr-1" }[justify]}
        onClick={() => onSort(sort === sortKey ? `-${sortKey}` : sortKey)}
      >
        {children}
      </SortHeaderButton>
    </TableHead>
  )
}

const sortIcons = { none: ArrowUpDown, ascending: ArrowUp, descending: ArrowDown } as const

/**
 * The one control of every sortable table header (SortableTableHead, DataTable): quiet at rest, the label and icon turn
 * to the foreground colour on hover, the shared focus ring, the 32 px hit area. The icon names the column's sort: up or
 * down while sorted, the two-way arrow while not; `direction` null is a column that cannot sort (no icon). The caller's
 * className holds its alignment offsets.
 */
function SortHeaderButton({ direction, className, children, ...props }: React.ComponentProps<typeof Button> & { direction: keyof typeof sortIcons | null }) {
  const Icon = direction === null ? null : sortIcons[direction]
  return (
    <Button variant="header" className={cn("max-w-full gap-1 px-1 text-xs has-[>svg]:px-1", className)} {...props}>
      <span className="whitespace-nowrap">{children}</span>
      {Icon && <Icon className="size-3" />}
    </Button>
  )
}

/**
 * Column roles for cells with unbounded text. Cells are nowrap by default, so in the auto table layout one long
 * unbreakable value sets its column's minimum and starves the wrapping column. A role bounds the cell's content box:
 * the primary text column wraps and never narrows below a readable minimum; a name column (one line that truncates with
 * its tooltip) keeps the same minimum and adds no intrinsic width (`contain: inline-size`), so a long name truncates
 * instead of widening the table; a code or metadata column is capped and wraps at the text's own break points (`|`,
 * `,`, `/`), breaking anywhere only as a fallback.
 * `breakAfter` makes a code value's top-level separator the only break: each part (a `|` range) gets its own line.
 */
const tableColumnContent = {
  primary: "min-w-48 whitespace-normal [overflow-wrap:anywhere]",
  name: "min-w-48 [contain:inline-size]",
  code: "min-w-36 max-w-56 font-mono text-xs whitespace-normal [overflow-wrap:anywhere]",
} as const

function TableCell({ className, column, breakAfter, children, ...props }: React.ComponentProps<"td"> & { column?: keyof typeof tableColumnContent; breakAfter?: string }) {
  const parts = breakAfter !== undefined && typeof children === "string" ? children.split(breakAfter).map((part, index, all) => (index < all.length - 1 ? part + breakAfter : part)) : null
  return (
    <td
      data-slot="table-cell"
      className={cn(
        "align-middle whitespace-nowrap [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]",
        tableCellPadding,
        className
      )}
      {...props}
    >
      {column ? (
        <div className={tableColumnContent[column]}>
          {parts ? parts.map((part, index) => <span key={index} className="inline-block max-w-full">{part}</span>) : children}
        </div>
      ) : children}
    </td>
  )
}

function TableCaption({
  className,
  ...props
}: React.ComponentProps<"caption">) {
  return (
    <caption
      data-slot="table-caption"
      className={cn("mt-4 text-sm text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Table,
  ListHeader,
  ListRow,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  SortableTableHead,
  SortHeaderButton,
  TableRow,
  TableCell,
  TableCaption,
}

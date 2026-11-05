import * as React from "react"
import { cn } from "@/lib/utils"
import type { SiteState } from "@/types/monitoring"
import { RowHintProvider, useRowHints } from "@/components/truncated-text"

export const tableHeaderBackground = "bg-muted"
export const tableHeaderText = "text-label font-[450] text-[var(--foreground-faint)]"
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
export const tableRowStyle = "border-b border-[var(--border-soft)] transition-colors hover:bg-accent has-aria-expanded:bg-accent data-[state=selected]:bg-[var(--primary-soft)]"

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
      className={cn("[&_tr]:border-b", tableHeaderBackground, tableHeaderText, className)}
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

function TableCell({ className, ...props }: React.ComponentProps<"td">) {
  return (
    <td
      data-slot="table-cell"
      className={cn(
        "align-middle whitespace-nowrap [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]",
        tableCellPadding,
        className
      )}
      {...props}
    />
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
  TableRow,
  TableCell,
  TableCaption,
}

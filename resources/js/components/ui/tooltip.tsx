import * as React from "react"
import { cn } from "@/lib/utils"
import { Tooltip as TooltipPrimitive } from "radix-ui"
import type { SiteState } from "@/types/monitoring"

/**
 * Tooltips hold no interactive content, so they are not hoverable: Radix's grace area towards a hoverable tooltip marks
 * the pointer "in transit" and blocks the adjacent trigger, which left the previous tooltip showing.
 */
function TooltipProvider({
  delayDuration = 0,
  disableHoverableContent = true,
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Provider>) {
  return (
    <TooltipPrimitive.Provider
      data-slot="tooltip-provider"
      delayDuration={delayDuration}
      disableHoverableContent={disableHoverableContent}
      {...props}
    />
  )
}

function Tooltip({
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Root>) {
  return <TooltipPrimitive.Root data-slot="tooltip" {...props} />
}

function TooltipTrigger({
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Trigger>) {
  return <TooltipPrimitive.Trigger data-slot="tooltip-trigger" {...props} />
}

export type TooltipDetailsProps = {
  subject: string
  facts?: readonly React.ReactNode[]
  status?: { state: SiteState; label: string }
  hint?: string
}

const tooltipStatusClass: Partial<Record<SiteState, string>> = {
  ok: "text-status-ok",
  warning: "text-status-warn",
  down: "text-status-down",
  failed: "text-status-down",
  "check-error": "text-status-error",
  maintenance: "text-status-info",
}

const tooltipPanelClass =
  "tooltip-panel z-50 w-fit max-w-[min(28rem,var(--radix-tooltip-content-available-width,calc(100vw-1rem)))] rounded-[6px] border border-tooltip-border bg-tooltip-surface px-[9px] py-1.5 font-mono text-xs leading-[17px] text-tooltip-subject shadow-overlay wrap-anywhere"

function TooltipDetails({ subject, facts, status, hint }: TooltipDetailsProps) {
  return (
    <div data-slot="tooltip-details" className="text-tooltip-muted">
      <p className="text-tooltip-subject">{subject}</p>
      {facts?.map((fact, index) => (
        <div key={index}>{fact}</div>
      ))}
      {status && (
        <p
          data-tooltip-status={status.state}
          className={tooltipStatusClass[status.state]}
        >
          {status.label}
        </p>
      )}
      {hint && <p>{hint}</p>}
    </div>
  )
}

function TooltipPanel({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="tooltip-content"
      role="tooltip"
      className={cn(
        tooltipPanelClass,
        "origin-(--radix-tooltip-content-transform-origin) motion-safe:animate-[motion-pop-in_var(--motion-enter)_var(--ease-out)] data-[state=closed]:animate-none data-[state=instant-open]:animate-none",
        className
      )}
      {...props}
    />
  )
}

function TooltipContent({
  className,
  sideOffset = 8,
  collisionPadding = 8,
  children,
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Content>) {
  return (
    <TooltipPrimitive.Portal>
      <TooltipPrimitive.Content
        asChild
        sideOffset={sideOffset}
        collisionPadding={collisionPadding}
        {...props}
      >
        <TooltipPanel className={className}>{children}</TooltipPanel>
      </TooltipPrimitive.Content>
    </TooltipPrimitive.Portal>
  )
}

export {
  Tooltip,
  TooltipTrigger,
  TooltipContent,
  TooltipDetails,
  TooltipPanel,
  TooltipProvider,
  tooltipPanelClass,
}

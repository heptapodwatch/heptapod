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
  status?: { state: SiteState | "running"; label: string }
  hint?: string
}

function TooltipDetails({ subject, facts, status, hint }: TooltipDetailsProps) {
  return (
    <div data-slot="tooltip-details">
      <p className="text-tooltip-subject">{subject}</p>
      {facts?.map((fact, index) => (
        <div key={index}>{fact}</div>
      ))}
      {status && <p data-tooltip-status={status.state}>{status.label}</p>}
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
        "tooltip-panel z-50 w-fit max-w-[min(28rem,calc(100vw-1rem))] rounded-[6px] border border-tooltip-border bg-tooltip-surface px-[9px] py-[6px] font-mono text-xs leading-[17px] text-tooltip-subject shadow-overlay",
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
}

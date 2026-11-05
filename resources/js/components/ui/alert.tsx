import { cva } from "class-variance-authority"
import { CircleAlert, Info, TriangleAlert, type LucideIcon } from "lucide-react"
import * as React from "react"

import { cn } from "@/lib/utils"

export type AlertTone = "info" | "warning" | "danger"

/** The tone owns border, tint and icon colour; callers never pass colour classes (heptapod-ui-rules Callouts). */
const alertVariants = cva(
  "relative flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-sm",
  {
    variants: {
      tone: {
        info: "border-border bg-muted/40 [&>[data-slot=alert-icon]]:text-(--status-info)",
        warning:
          "border-(--status-warn)/40 bg-(--status-warn)/5 [&>[data-slot=alert-icon]]:text-(--status-warn)",
        danger:
          "border-destructive/40 bg-destructive/5 [&>[data-slot=alert-icon]]:text-destructive",
      },
    },
  }
)

const toneIcon: Record<AlertTone, LucideIcon> = {
  info: Info,
  warning: TriangleAlert,
  danger: CircleAlert,
}

/**
 * The one callout. Info is announced politely (status); warning and danger interrupt (alert).
 * An optional action (a link or button) sits after the text and wraps below it on narrow screens.
 * No className: spacing belongs to the parent's gap, colour to the tone.
 */
function Alert({
  tone,
  action,
  children,
  ...props
}: Omit<React.ComponentProps<"div">, "children" | "className" | "role"> & {
  tone: AlertTone
  action?: React.ReactNode
  children: React.ReactNode
}) {
  const Icon = toneIcon[tone]
  return (
    <div
      data-slot="alert"
      data-tone={tone}
      role={tone === "info" ? "status" : "alert"}
      className={alertVariants({ tone })}
      {...props}
    >
      <Icon
        data-slot="alert-icon"
        aria-hidden="true"
        className="mt-0.5 size-4 shrink-0"
      />
      <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2">
        <div className="min-w-0 flex-1 basis-64 space-y-0.5">{children}</div>
        {action !== undefined && (
          <div data-slot="alert-action" className="flex shrink-0 items-center gap-2">
            {action}
          </div>
        )}
      </div>
    </div>
  )
}

function AlertTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-title"
      className={cn("font-medium text-foreground", className)}
      {...props}
    />
  )
}

function AlertDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-description"
      className={cn(
        "space-y-1 text-muted-foreground [&_p]:leading-relaxed",
        className
      )}
      {...props}
    />
  )
}

export { Alert, AlertTitle, AlertDescription }

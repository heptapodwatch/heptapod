import * as React from "react"
import { cn } from "@/lib/utils"
import { CheckIcon } from "lucide-react"
import { Checkbox as CheckboxPrimitive } from "radix-ui"

function Checkbox({
  className,
  ...props
}: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      className={cn(
        "relative after:absolute after:-inset-3 sm:after:hidden peer size-4 shrink-0 rounded-[4px] border border-control-border bg-muted transition-shadow outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground dark:aria-invalid:ring-destructive/40 dark:data-[state=checked]:bg-primary",
        className
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator
        data-slot="checkbox-indicator"
        className="grid place-content-center text-current transition-none"
      >
        <CheckIcon className="size-3.5" />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  )
}

/**
 * A labelled checkbox row: 40 px touch target below sm, 32 px on desktop. The optional description sits under the label and
 * is the checkbox's accessible description, not part of its name.
 */
function CheckboxField({
  children,
  description,
  className,
  ...props
}: React.ComponentProps<typeof CheckboxPrimitive.Root> & {
  children: React.ReactNode
  description?: React.ReactNode
}) {
  const generatedId = React.useId()
  const id = props.id ?? generatedId
  const describedBy = [props["aria-describedby"], description ? `${id}-description` : null]
    .filter(Boolean)
    .join(" ")
  return (
    <div className={cn("min-w-0", className)}>
      <label
        htmlFor={id}
        className="flex min-h-10 cursor-pointer items-center gap-2.5 text-cell has-disabled:cursor-not-allowed has-disabled:opacity-50 sm:min-h-8"
      >
        <Checkbox {...props} id={id} aria-describedby={describedBy || undefined} />
        <span className="flex min-w-0 flex-wrap items-center gap-2">{children}</span>
      </label>
      {description && (
        <p id={`${id}-description`} className="pl-6.5 text-xs text-muted-foreground">
          {description}
        </p>
      )}
    </div>
  )
}

export { Checkbox, CheckboxField }

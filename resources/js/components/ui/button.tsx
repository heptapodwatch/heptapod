import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { Slot } from "radix-ui"

const buttonVariants = cva(
  "inline-flex min-h-10 min-w-10 shrink-0 items-center sm:min-h-0 sm:min-w-0 justify-center gap-2 rounded-full text-cell font-medium whitespace-nowrap focus-ring inactive:pointer-events-none aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default:
          "press bg-primary text-primary-foreground hover:bg-primary-hover active:bg-primary-active inactive:border-transparent inactive:bg-disabled-primary inactive:text-disabled-primary-foreground",
        destructive:
          "press bg-destructive text-destructive-foreground hover:bg-destructive-hover active:bg-destructive-active inactive:border-transparent inactive:bg-disabled-destructive inactive:text-disabled-destructive-foreground",
        outline:
          "press border border-border bg-secondary text-foreground hover:border-border-strong hover:bg-surface-hover active:bg-surface-active inactive:border-dashed inactive:border-border inactive:bg-disabled inactive:text-disabled-foreground",
        ghost:
          "press bg-transparent hover:bg-ghost-hover active:bg-ghost-active inactive:bg-transparent inactive:text-disabled-ghost-foreground",
      },
      size: {
        default: "h-8 gap-1.5 px-3 leading-none has-[>svg]:px-2.5",
        xs: "h-6 gap-1 px-2 text-xs has-[>svg]:px-1.5 [&_svg:not([class*='size-'])]:size-3",
        icon: "size-8 leading-none",
        "icon-xs":
          "size-6 leading-none [&_svg:not([class*='size-'])]:size-3",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot.Root : "button"

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }

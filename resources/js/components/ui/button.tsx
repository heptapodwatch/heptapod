import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { Slot } from "radix-ui"

const buttonVariants = cva(
  "inline-flex min-h-10 min-w-10 shrink-0 items-center sm:min-h-0 sm:min-w-0 justify-center gap-2 rounded-full text-cell font-medium whitespace-nowrap focus-ring disabled:pointer-events-none aria-disabled:pointer-events-none aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default:
          "press bg-primary text-primary-foreground hover:bg-primary-hover active:bg-primary-active disabled:border-transparent disabled:bg-disabled-primary disabled:text-disabled-primary-foreground aria-disabled:border-transparent aria-disabled:bg-disabled-primary aria-disabled:text-disabled-primary-foreground",
        destructive:
          "press bg-destructive text-destructive-foreground hover:bg-destructive-hover active:bg-destructive-active disabled:border-transparent disabled:bg-disabled-destructive disabled:text-disabled-destructive-foreground aria-disabled:border-transparent aria-disabled:bg-disabled-destructive aria-disabled:text-disabled-destructive-foreground",
        outline:
          "press border border-border bg-secondary text-foreground hover:border-border-strong hover:bg-surface-hover active:bg-surface-active disabled:border-dashed disabled:border-disabled-border disabled:bg-disabled disabled:text-disabled-foreground aria-disabled:border-dashed aria-disabled:border-disabled-border aria-disabled:bg-disabled aria-disabled:text-disabled-foreground",
        secondary:
          "press bg-secondary text-secondary-foreground hover:bg-surface-hover active:bg-surface-active disabled:border-transparent disabled:bg-disabled-secondary disabled:text-disabled-foreground aria-disabled:border-transparent aria-disabled:bg-disabled-secondary aria-disabled:text-disabled-foreground",
        ghost:
          "press bg-transparent hover:bg-ghost-hover active:bg-ghost-active disabled:bg-transparent disabled:text-disabled-ghost-foreground aria-disabled:bg-transparent aria-disabled:text-disabled-ghost-foreground",
        link: "bg-transparent text-primary underline decoration-transparent decoration-1 underline-offset-4 hover:decoration-current disabled:text-disabled-ghost-foreground disabled:decoration-transparent aria-disabled:text-disabled-ghost-foreground aria-disabled:decoration-transparent",
      },
      size: {
        default: "h-8 gap-1.5 rounded-full px-3 leading-none has-[>svg]:px-2.5",
        xs: "h-6 gap-1 rounded-full px-2 text-xs has-[>svg]:px-1.5 [&_svg:not([class*='size-'])]:size-3",
        sm: "h-8 gap-1.5 rounded-full px-3 leading-none has-[>svg]:px-2.5",
        icon: "size-8 leading-none",
        "icon-xs":
          "size-6 rounded-full leading-none [&_svg:not([class*='size-'])]:size-3",
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

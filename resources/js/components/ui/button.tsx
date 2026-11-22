import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { Slot } from "radix-ui"

const buttonVariants = cva(
  "inline-flex min-h-10 min-w-10 shrink-0 items-center sm:min-h-0 sm:min-w-0 justify-center gap-2 rounded-full text-cell font-medium whitespace-nowrap quiet-focus disabled:pointer-events-none aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default: "quiet-press quiet-solid-primary",
        destructive: "quiet-press quiet-solid-destructive",
        outline: "quiet-press quiet-outline",
        secondary: "quiet-press quiet-secondary",
        ghost: "quiet-press quiet-ghost",
        link: "quiet-link",
      },
      size: {
        default: "h-8 gap-1.5 rounded-full px-3 quiet-center has-[>svg]:px-2.5",
        xs: "h-6 gap-1 rounded-full px-2 text-xs has-[>svg]:px-1.5 [&_svg:not([class*='size-'])]:size-3",
        sm: "h-8 gap-1.5 rounded-full px-3 quiet-center has-[>svg]:px-2.5",
        icon: "size-8 quiet-center",
        "icon-xs": "size-6 rounded-full quiet-center [&_svg:not([class*='size-'])]:size-3",
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

import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { Slot } from "radix-ui"

const badgeVariants = cva(
  "inline-flex w-fit shrink-0 items-center justify-center gap-1 overflow-hidden rounded-full border border-transparent px-2.5 py-0.5 text-label font-[450] whitespace-nowrap aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&>svg]:pointer-events-none [&>svg]:size-3",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground [a&]:hover:bg-(--primary-hover) [a&]:active:bg-(--primary-active)",
        secondary:
          "bg-secondary text-secondary-foreground [a&]:hover:bg-(--surface-hover) [a&]:active:bg-(--surface-active)",
        destructive:
          "bg-destructive text-destructive-foreground [a&]:hover:bg-(--destructive-hover) [a&]:active:bg-(--destructive-active)",
        outline:
          "border-border text-foreground [a&]:hover:border-border-strong [a&]:hover:bg-(--surface-hover) [a&]:active:bg-(--surface-active)",
        ghost:
          "[a&]:hover:bg-(--ghost-hover) [a&]:active:bg-(--ghost-active)",
        link: "text-primary underline decoration-transparent decoration-1 underline-offset-4 [a&]:hover:decoration-current",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant = "default",
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot.Root : "span"

  return (
    <Comp
      data-slot="badge"
      data-variant={variant}
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }

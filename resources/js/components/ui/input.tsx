import * as React from "react"
import { cn } from "@/lib/utils"

export const fieldSurfaceClass =
  "w-full min-w-0 rounded-md border border-input bg-muted selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 aria-invalid:[--ring:var(--destructive)] dark:aria-invalid:ring-destructive/40"

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        fieldSurfaceClass,
        "h-10 sm:h-8 px-3 py-1 text-base leading-none focus-ring disabled:pointer-events-none md:text-cell",
        type === "search" && "rounded-full",
        className
      )}
      {...props}
    />
  )
}

export { Input }

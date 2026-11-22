import * as React from "react"
import { fieldSurfaceClass } from "@/components/ui/input"
import { cn } from "@/lib/utils"

function Textarea({ className, ...props }: React.ComponentProps<"textarea">) {
  return (
    <textarea
      data-slot="textarea"
      className={cn(
        fieldSurfaceClass,
        "flex field-sizing-content min-h-16 px-3 py-2 text-base focus-ring md:text-cell",
        className
      )}
      {...props}
    />
  )
}

export { Textarea }

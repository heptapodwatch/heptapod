"use client"

import * as React from "react"
import { cn } from "@/lib/utils"
import * as RechartsPrimitive from "recharts"
import type { TooltipValueType } from "recharts"
import { TooltipPanel, TooltipDetails, type TooltipDetailsProps } from "@/components/ui/tooltip"
import { useTranslation } from "react-i18next"
import { Slot } from "radix-ui"

// Format: { THEME_NAME: CSS_SELECTOR }
const THEMES = { light: "", dark: ".dark" } as const

const INITIAL_DIMENSION = { width: 320, height: 200 } as const
type TooltipNameType = number | string

export type ChartConfig = Record<
  string,
  {
    label?: React.ReactNode
    icon?: React.ComponentType
  } & (
    | { color?: string; theme?: never }
    | { color?: never; theme: Record<keyof typeof THEMES, string> }
  )
>

type ChartContextProps = {
  config: ChartConfig
  containerRef: React.RefObject<HTMLDivElement | null>
}

const ChartContext = React.createContext<ChartContextProps | null>(null)

function useChart() {
  const context = React.useContext(ChartContext)

  if (!context) {
    throw new Error("useChart must be used within a <ChartContainer />")
  }

  return context
}

function ChartContainer({
  id,
  className,
  children,
  config,
  initialDimension = INITIAL_DIMENSION,
  ...props
}: React.ComponentProps<"div"> & {
  config: ChartConfig
  children: React.ComponentProps<
    typeof RechartsPrimitive.ResponsiveContainer
  >["children"]
  initialDimension?: {
    width: number
    height: number
  }
}) {
  const uniqueId = React.useId()
  const containerRef = React.useRef<HTMLDivElement>(null)
  const chartId = `chart-${id ?? uniqueId.replace(/:/g, "")}`

  return (
    <ChartContext.Provider value={{ config, containerRef }}>
      {/* Compose the measurement ref with any caller-provided div ref. */}
      <Slot.Root ref={containerRef}>
        <div
          data-slot="chart"
          data-chart={chartId}
          role="group"
          className={cn(
            "flex aspect-video justify-center font-mono text-xs tabular-nums [&_.recharts-cartesian-axis-tick-value]:fill-muted-foreground [&_.recharts-cartesian-grid_line[stroke='#ccc']]:stroke-border/50 [&_.recharts-curve.recharts-tooltip-cursor]:stroke-border [&_.recharts-dot[stroke='#fff']]:stroke-transparent [&_.recharts-layer]:outline-hidden [&_.recharts-polar-grid_[stroke='#ccc']]:stroke-border [&_.recharts-radial-bar-background-sector]:fill-muted [&_.recharts-rectangle.recharts-tooltip-cursor]:fill-muted [&_.recharts-reference-line_[stroke='#ccc']]:stroke-border [&_.recharts-sector]:outline-hidden [&_.recharts-sector[stroke='#fff']]:stroke-transparent [&_.recharts-surface]:outline-hidden",
            className
          )}
          {...props}
        >
          <ChartStyle id={chartId} config={config} />
          <RechartsPrimitive.ResponsiveContainer
            initialDimension={initialDimension}
          >
            {children}
          </RechartsPrimitive.ResponsiveContainer>
        </div>
      </Slot.Root>
    </ChartContext.Provider>
  )
}

const ChartStyle = ({ id, config }: { id: string; config: ChartConfig }) => {
  const colorConfig = Object.entries(config).filter(
    ([, config]) => config.theme ?? config.color
  )

  if (!colorConfig.length) {
    return null
  }

  return (
    <style
      dangerouslySetInnerHTML={{
        __html: Object.entries(THEMES)
          .map(
            ([theme, prefix]) => `
${prefix} [data-chart=${id}] {
${colorConfig
  .map(([key, itemConfig]) => {
    const color =
      itemConfig.theme?.[theme as keyof typeof itemConfig.theme] ??
      itemConfig.color
    return color ? `  --color-${key}: ${color};` : null
  })
  .join("\n")}
}
`
          )
          .join("\n"),
      }}
    />
  )
}

function ChartTooltip(
  props: React.ComponentProps<typeof RechartsPrimitive.Tooltip>
) {
  const { containerRef } = useChart()
  const coordinate = RechartsPrimitive.useActiveTooltipCoordinate()
  const [reverseY, setReverseY] = React.useState(false)
  React.useLayoutEffect(() => {
    if (!coordinate) return
    const updateDirection = () => {
      const container = containerRef.current
      if (container) {
        setReverseY(
          container.getBoundingClientRect().top + coordinate.y > window.innerHeight / 2
        )
      }
    }
    updateDirection()
    window.addEventListener("scroll", updateDirection, true)
    window.addEventListener("resize", updateDirection)
    return () => {
      window.removeEventListener("scroll", updateDirection, true)
      window.removeEventListener("resize", updateDirection)
    }
  }, [coordinate, containerRef])
  return (
    <RechartsPrimitive.Tooltip
      {...props}
      offset={10}
      allowEscapeViewBox={{ x: false, y: true }}
      reverseDirection={{ x: false, y: reverseY }}
    />
  )
}

function ChartTooltipContent({
  active,
  payload,
  className,
  label,
  labelFormatter,
  formatter,
  subject,
  status,
  accessibilityLayer,
}: React.ComponentProps<typeof RechartsPrimitive.Tooltip> &
  Pick<React.ComponentProps<"div">, "className"> & {
    subject: string
    status?: TooltipDetailsProps["status"]
    accessibilityLayer?: boolean
  } & Omit<
    RechartsPrimitive.DefaultTooltipContentProps<TooltipValueType, TooltipNameType>,
    "accessibilityLayer"
  >) {
  const { config } = useChart()
  const { i18n } = useTranslation()
  const plotArea = RechartsPrimitive.usePlotArea()
  if (!active || !payload?.length) return null
  if (!plotArea) throw new Error("Chart tooltip requires its plot area")
  const timestamp = labelFormatter ? labelFormatter(label, payload) : label
  const facts: React.ReactNode[] = timestamp ? [timestamp] : []
  payload
    .filter((item) => item.type !== "none")
    .forEach((item, index) => {
      if (item.value == null) return
      const key = String(item.dataKey ?? item.name ?? "value")
      const itemConfig = getPayloadConfigFromPayload(config, item, key)
      facts.push(
        formatter && item.name ? (
          formatter(item.value, item.name, item, index, item.payload)
        ) : (
          <React.Fragment key={key}>
            {itemConfig?.label ?? item.name}: {typeof item.value === "number"
              ? item.value.toLocaleString(i18n.language)
              : String(item.value)}
          </React.Fragment>
        )
      )
    })
  // Leave room for the pointer offset inside narrow chart cards.
  return (
    <TooltipPanel
      className={className}
      style={{
        maxWidth: `min(28rem, ${Math.max(0, plotArea.width - 16)}px, calc(100vw - 1rem))`,
      }}
      aria-live={accessibilityLayer ? "polite" : undefined}
      aria-atomic={accessibilityLayer || undefined}
    >
      <TooltipDetails subject={subject} facts={facts} status={status} />
    </TooltipPanel>
  )
}

const ChartLegend = RechartsPrimitive.Legend

function ChartLegendContent({
  className,
  hideIcon = false,
  payload,
  verticalAlign = "bottom",
  nameKey,
}: React.ComponentProps<"div"> & {
  hideIcon?: boolean
  nameKey?: string
} & RechartsPrimitive.DefaultLegendContentProps) {
  const { config } = useChart()

  if (!payload?.length) {
    return null
  }

  return (
    <div
      className={cn(
        "flex items-center justify-center gap-4",
        verticalAlign === "top" ? "pb-3" : "pt-3",
        className
      )}
    >
      {payload
        .filter((item) => item.type !== "none")
        .map((item, index) => {
          const key = `${nameKey ?? item.dataKey ?? "value"}`
          const itemConfig = getPayloadConfigFromPayload(config, item, key)

          return (
            <div
              key={index}
              className={cn(
                "flex items-center gap-1.5 [&>svg]:h-3 [&>svg]:w-3 [&>svg]:text-muted-foreground"
              )}
            >
              {itemConfig?.icon && !hideIcon ? (
                <itemConfig.icon />
              ) : (
                <div
                  className="h-2 w-2 shrink-0 rounded-[2px]"
                  style={{
                    backgroundColor: item.color,
                  }}
                />
              )}
              {itemConfig?.label}
            </div>
          )
        })}
    </div>
  )
}

// Helper to extract item config from a payload.
function getPayloadConfigFromPayload(
  config: ChartConfig,
  payload: unknown,
  key: string
) {
  if (typeof payload !== "object" || payload === null) {
    return undefined
  }

  const payloadPayload =
    "payload" in payload &&
    typeof payload.payload === "object" &&
    payload.payload !== null
      ? payload.payload
      : undefined

  let configLabelKey: string = key

  if (
    key in payload &&
    typeof payload[key as keyof typeof payload] === "string"
  ) {
    configLabelKey = payload[key as keyof typeof payload] as string
  } else if (
    payloadPayload &&
    key in payloadPayload &&
    typeof payloadPayload[key as keyof typeof payloadPayload] === "string"
  ) {
    configLabelKey = payloadPayload[
      key as keyof typeof payloadPayload
    ] as string
  }

  return configLabelKey in config ? config[configLabelKey] : config[key]
}

export {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  ChartLegend,
  ChartLegendContent,
  ChartStyle,
}

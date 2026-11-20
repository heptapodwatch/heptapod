import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { PageContainer } from '@/components/page-container';
import { Button } from '@/components/ui/button';
import { attachErrorMark } from '@/lib/error-mark';
import { focusRingClass } from '@/lib/interaction';
import { cn } from '@/lib/utils';

const SEGMENTS = [
    { key: 'a', x: 49, y: 19.5, width: 22, height: 13, lit: false },
    { key: 'b', x: 73.5, y: 28.5, width: 13, height: 29, lit: true },
    { key: 'c', x: 73.5, y: 62.5, width: 13, height: 29, lit: true },
    { key: 'd', x: 49, y: 87.5, width: 22, height: 13, lit: false },
    { key: 'e', x: 33.5, y: 62.5, width: 13, height: 29, lit: true },
    { key: 'f', x: 33.5, y: 28.5, width: 13, height: 29, lit: true },
    { key: 'g', x: 49, y: 53.5, width: 22, height: 13, lit: true },
] as const;

export default function ErrorPage({
    status,
    title,
    description,
    recovery_url,
    recovery_label,
    secondary_url,
    secondary_label,
}: Heptapod.Data.Ui.ErrorPageData) {
    const hostRef = useRef<HTMLSpanElement | null>(null);
    const svgRef = useRef<SVGSVGElement | null>(null);

    useEffect(() => {
        const svg = svgRef.current;
        const host = hostRef.current;
        if (!svg || !host) return;
        return attachErrorMark(svg, status, host);
    }, [status]);

    return (
        <PageContainer>
            <Head title={title} />
            <div className="flex min-h-[60vh] flex-col items-center justify-center py-12">
                <div className="flex w-full max-w-md flex-col items-center gap-6 text-center">
                    <span
                        ref={hostRef}
                        tabIndex={0}
                        role="img"
                        aria-label="Heptapod"
                        data-error-mark={status}
                        className={cn(
                            'hp-brand rounded-md [--brand-mark-h:28px]',
                            focusRingClass,
                        )}
                    >
                        <svg
                            ref={svgRef}
                            className="hp-mark"
                            data-state="still"
                            viewBox="33.5 19.5 53 81"
                            aria-hidden="true"
                            focusable="false"
                        >
                            {SEGMENTS.map((segment) => (
                                <rect
                                    key={segment.key}
                                    data-seg={segment.key}
                                    data-lit={segment.lit ? '' : undefined}
                                    className={`hp-seg hp-${segment.key}`}
                                    rx="6.5"
                                    x={segment.x}
                                    y={segment.y}
                                    width={segment.width}
                                    height={segment.height}
                                />
                            ))}
                        </svg>
                        <span className="hp-word" aria-hidden="true" />
                    </span>
                    <div className="space-y-2">
                        <p className="font-mono text-sm text-muted-foreground">
                            HTTP {status}
                        </p>
                        <h1 className="text-page-title">{title}</h1>
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center justify-center gap-2">
                        <Button asChild>
                            <Link href={recovery_url}>{recovery_label}</Link>
                        </Button>
                        {secondary_url && secondary_label && (
                            <Button asChild variant="outline">
                                <Link href={secondary_url}>
                                    {secondary_label}
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </PageContainer>
    );
}

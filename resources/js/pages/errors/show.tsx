import { Head, Link } from '@inertiajs/react';
import { PageContainer } from '@/components/page-container';
import { Button } from '@/components/ui/button';

export default function ErrorPage({
    status,
    title,
    description,
    recovery_url,
    recovery_label,
    secondary_url,
    secondary_label,
}: Heptapod.Data.Ui.ErrorPageData) {
    return (
        <PageContainer>
            <Head title={title} />
            <div className="flex min-h-[60vh] flex-col items-center justify-center py-12">
                <div className="flex w-full max-w-md flex-col items-center gap-6 text-center">
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

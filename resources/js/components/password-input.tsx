import { useTranslation } from 'react-i18next';
import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export default function PasswordInput({
    className,
    ref,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & { ref?: Ref<HTMLInputElement> }) {
    const { t } = useTranslation();
    const [showPassword, setShowPassword] = useState(false);

    return (
        <div className="relative">
            <Input
                type={showPassword ? 'text' : 'password'}
                className={cn('pr-10', className)}
                ref={ref}
                {...props}
            />
            <Button
                type="button"
                variant="ghost"
                size="icon-xs"
                onClick={() => setShowPassword((prev) => !prev)}
                className="quiet-focus-inset absolute top-1/2 right-0 -translate-y-1/2 text-muted-foreground hover:text-foreground sm:right-1"
                aria-label={
                    showPassword ? t('Hide password') : t('Show password')
                }
            >
                {showPassword ? (
                    <EyeOff className="size-4" />
                ) : (
                    <Eye className="size-4" />
                )}
            </Button>
        </div>
    );
}

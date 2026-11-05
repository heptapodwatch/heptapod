import {
    horizontalNavigationClass,
    horizontalNavigationItemClass,
    horizontalNavigationActiveClass,
} from '@/lib/horizontal-navigation';
import { focusRingClass } from '@/lib/interaction';
import { cn } from '@/lib/utils';

export function Stepper({
    steps,
    current,
    label,
    onStepChange,
    disabled = false,
}: {
    steps: string[];
    current: number;
    label: string;
    onStepChange?: (index: number) => void;
    disabled?: boolean;
}) {
    return (
        <ol aria-label={label} className={horizontalNavigationClass}>
            {steps.map((title, index) => (
                <li
                    key={title}
                    aria-current={index === current ? 'step' : undefined}
                    className="shrink-0"
                >
                    {onStepChange ? (
                        <button
                            type="button"
                            className={cn(
                                horizontalNavigationItemClass,
                                index === current &&
                                    horizontalNavigationActiveClass,
                                focusRingClass,
                                'focus-visible:ring-inset',
                            )}
                            disabled={disabled}
                            onClick={() => onStepChange(index)}
                        >
                            <span className="font-mono tabular-nums">
                                {index + 1}.
                            </span>{' '}
                            {title}
                        </button>
                    ) : (
                        <span
                            className={cn(
                                horizontalNavigationItemClass,
                                index === current &&
                                    horizontalNavigationActiveClass,
                            )}
                        >
                            <span className="font-mono tabular-nums">
                                {index + 1}.
                            </span>{' '}
                            {title}
                        </span>
                    )}
                </li>
            ))}
        </ol>
    );
}

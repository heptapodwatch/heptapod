/**
 * The shared interaction class sets (heptapod-ui-rules): one hover tint for clickable rows and cards, one keyboard focus
 * ring. The ui primitives spell the same utilities inline; scripts/check-ui-primitives.php rejects other focus-ring values.
 */
export const rowHoverClass = 'hover:bg-accent';

export const focusRingClass =
    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

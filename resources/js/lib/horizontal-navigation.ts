/** Navigation and numbered steps share ink, underline and hit areas, not semantics. */
export const horizontalNavigationClass =
    'flex max-w-full gap-4 overflow-x-auto border-b';
export const horizontalNavigationItemClass =
    'inline-flex h-10 shrink-0 items-center gap-1.5 border-b-2 border-transparent px-2 text-cell font-[450] whitespace-nowrap text-muted-foreground sm:h-8';
export const horizontalNavigationActiveClass =
    'border-b-foreground font-medium text-foreground';
/** A 24 px edge fade on the side(s) that scroll, so a cut-off tab reads as "more this way". */
export const horizontalNavigationFadeClass =
    'data-[overflow-start]:data-[overflow-end]:mask-[linear-gradient(to_right,transparent,black_24px,black_calc(100%-24px),transparent)] data-[overflow-start]:mask-[linear-gradient(to_right,transparent,black_24px)] data-[overflow-end]:mask-[linear-gradient(to_left,transparent,black_24px)]';

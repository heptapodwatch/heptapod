<?php

declare(strict_types=1);

/*
 * Guards for the shared UI primitives (heptapod-ui-rules):
 * - confirmations use ConfirmDialog, never the browser's native confirm/alert/prompt;
 * - text links use TextLink or EntityLink, never an underline class on a hand-styled <Link>/<a>;
 * - link hover never changes the text colour: no hover:text-* on a <Link>/<a> or in the link primitives;
 * - only the link primitives style their [data-link-text] span; callers use props (truncate);
 * - keyboard focus uses the one ring (focus-visible:ring-2 ring-ring, optionally ring-inset): no 3 px or translucent rings,
 *   offsets, outlines, ring removal or focus: (non-visible) rings.
 * Each allowlist entry names a reviewed exception and its reason.
 */

$root = dirname(__DIR__);
/** Native list items that are timeline entries, not record rows. Match the complete class expression. */
$timelineRowClasses = [
    'resources/js/components/monitoring.tsx' => '"relative flex gap-4 border-l pb-6 pl-4 last:pb-0"',
];
$densityToken = '~\\b(?:tableCellPadding|tableRowHeight|listRowPadding|listCardPadding)\\b~';
$densityOverride = '~(?:^|[\\s\'"`:])!?(?:(?:min-|max-)?h-|p(?:y|t|b)?-)~';
/**
 * Per element: the file and an attribute that only that element's opening tag carries (before its className).
 * The exception covers exactly one element: the file must contain exactly one <Link>/<a> opening tag with the marker.
 */
$hoverColourAllowlist = [
    'resources/js/components/site-header.tsx' => ["aria-label={t('Open site')}", 'Icon-only "Open site" control: no text and no underline, so not a text link.'],
    'resources/js/components/section-tabs.tsx' => ['aria-current=', 'Section tabs: navigation tab control, not a text link'],
];
/** Per file: the one other focus-ring utility it may use, and why. */
$focusRingAllowlist = [
    'resources/js/components/incident-banner.tsx' => ['focus-visible:ring-current', 'Red incident strip: the shared ring colour is invisible on red.'],
];
/** Raw headings that are not section titles: exact element markers and expected counts. */
$sectionHeadingExceptions = [
    'resources/js/components/foundation-primitives.tsx' => ['text-headline', 1, 'StateSentence: the Sites health headline (headline token), not a section title.'],
    'resources/js/components/incident-episode.tsx' => ['pt-2 pb-3', 1, 'Compact timeline calendar-day divider.'],
    'resources/js/pages/clients/show.tsx' => ['sr-only', 2, 'Invisible headings name the Incidents and Messages tab panels; Sites uses PageHeader.'],
];
$sectionHeadingExceptionCounts = [];
/** Identity controls, not page actions: each file has exactly one large sidebar control. */
$largeSidebarButtonExceptions = [
    'resources/js/components/app-sidebar.tsx' => 'Brand lockup: the logo needs its identity row, not a compact action button.',
    'resources/js/components/nav-user.tsx' => 'User menu: avatar and two identity lines need their identity row, not a compact action button.',
];
$largeSidebarButtonExceptionCounts = [];
/** Per file: the one call with a purpose-specific clock, keyed by a snippet of its whitespace-collapsed arguments; not a standalone display timestamp. */
$dateTimeCallExceptions = [
    'resources/js/components/chart-area-interactive.tsx' => ["active.key === '24h'", 'Response chart axis: clock ticks for the 24-hour range, calendar ticks for longer ranges.'],
    'resources/js/components/response-breakdown.tsx' => ["(String(value), { hour: '2-digit', minute: '2-digit', })", 'Response phase chart axis: compact hour/minute ticks.'],
    'resources/js/components/performance-evidence.tsx' => ["hourCycle: 'h23'", 'Performance chart axis: compact hour/minute ticks.'],
    'resources/js/components/ui/chart.tsx' => ['i18n.language', 'Numeric chart tooltip value: Number.toLocaleString, not a date formatter.'],
];
$dateTimeCallExceptionCounts = [];
/** Per file: the one tiny circle (keyed by a snippet of its className) that carries no monitoring state. */
$filledDotExceptions = [
    'resources/js/components/ui/avatar.tsx' => ['group-data-[size=sm]/avatar:size-2', 'Avatar badge primitive: a decorative identity badge, not a monitoring result.'],
    'resources/js/components/ui/radio-group.tsx' => ['bg-current', 'Radio selection indicator: a form choice, not a monitoring result.'],
];
$filledDotExceptionCounts = [];
/** Full-bleed strips that are not page callouts: they keep their own tint (heptapod-ui-rules Callouts). */
$calloutTintExceptions = [
    'resources/js/components/incident-banner.tsx' => 'Full-bleed incident strip above the site page, not a page callout.',
    'resources/js/components/stream-lost-strip.tsx' => 'The 32 px app-shell connection strip, not a page callout.',
];
/** Sheets that are not a SheetForm record editor (heptapod-ui-rules Containers). */
$sheetContentOwners = [
    'resources/js/components/sheet-form.tsx' => 'The record create/edit sheet primitive.',
    'resources/js/components/ui/sidebar.tsx' => 'The mobile sidebar drawer, not a form.',
    'resources/js/components/multi-step-sheet.tsx' => 'Design-preview stepper sheet on /dev/components, not routed.',
    'resources/js/components/foundation-primitives.tsx' => 'Design-preview sheet on /dev/components, not routed.',
];
/** Raw <button> elements outside components/ui/: each is a shared primitive or a row target that draws the shared ring itself. Every other button is Button. */
$rawButtonOwners = [
    'resources/js/components/data-table.tsx' => [1, 'Mobile site card: the whole card is the row target (rowTargetClass).'],
    'resources/js/components/filter-bar.tsx' => [1, 'FilterBar clear (x) control drawn inside the filter trigger.'],
    'resources/js/components/info-tooltip.tsx' => [1, 'InfoTooltip primitive: the 16 px info trigger.'],
    'resources/js/components/monitoring.tsx' => [1, 'UptimeBar: the strip is one tab stop with Left/Right day navigation.'],
    'resources/js/components/password-input.tsx' => [1, 'PasswordInput primitive: the visibility toggle inside the field.'],
    'resources/js/components/recipient-settings/recipient-settings.tsx' => [1, 'Channel row name: the row tab stop (rowTargetClick).'],
    'resources/js/components/stepper.tsx' => [1, 'Stepper primitive: numbered step buttons.'],
    'resources/js/components/text-link.tsx' => [1, 'TextLinkButton primitive: a button that looks like a text link.'],
];
/** Per file: the one error key a button action returns (no form field holds it), and why it has no field link. */
$actionErrorKeys = [
    'resources/js/components/incident-episode.tsx' => 'check', // Run check refused (api.run_unavailable): the header action's result.
    'resources/js/pages/sites/settings.tsx' => 'icon', // Refresh icon refused (unavailable / throttled): the Refresh button's result.
];
/** Per file: the one destructive-coloured paragraph (keyed by a snippet of its className) that is not an inline error. Every other text-destructive <p> must be InputError. */
$destructiveTextExceptions = [];
$destructiveTextExceptionCounts = [];
$underlineAllowlist = [
    'resources/js/components/text-link.tsx' => 'The text link primitive.',
    'resources/js/components/entity-link.tsx' => 'The entity link primitive.',
];

/** Read a quoted value or balanced expression, including nested calls, objects, strings and templates. */
function expressionValue(string $source, int $start): string
{
    $quote = $source[$start] ?? '';
    if ($quote === '"' || $quote === "'") {
        $end = strpos($source, $quote, $start + 1);
        if ($end === false) {
            throw new RuntimeException('Unterminated className string at offset '.$start);
        }

        return substr($source, $start, $end - $start + 1);
    }
    $closers = ['{' => '}', '(' => ')', '[' => ']'];
    if (! isset($closers[$quote])) {
        throw new RuntimeException('Unsupported expression at offset '.$start);
    }
    $contexts = [$quote];
    $length = strlen($source);
    for ($i = $start + 1; $i < $length; $i++) {
        $char = $source[$i];
        $context = $contexts[array_key_last($contexts)];
        if (! isset($closers[$context])) {
            if ($char === '\\') {
                $i++;
            } elseif ($char === $context) {
                array_pop($contexts);
            } elseif ($context === '`' && $char === '$' && ($source[$i + 1] ?? '') === '{') {
                $contexts[] = '{';
                $i++;
            }

            continue;
        }
        if ($char === '"' || $char === "'" || $char === '`' || isset($closers[$char])) {
            $contexts[] = $char;
        } elseif ($char === $closers[$context]) {
            array_pop($contexts);
            if ($contexts === []) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('Unbalanced expression at offset '.$start);
}

/**
 * Whether an opening-tag prefix carries $marker as an attribute: the marker must start an attribute (after whitespace, outside
 * any quoted or {…} value). Marker text inside another attribute's value, e.g. title="aria-current=", does not count.
 */
function tagHasAttribute(string $tag, string $marker): bool
{
    $contexts = [];
    $length = strlen($tag);
    for ($i = 0; $i < $length; $i++) {
        $char = $tag[$i];
        if ($contexts !== []) {
            $context = $contexts[array_key_last($contexts)];
            if ($context === '{') {
                if ($char === '"' || $char === "'" || $char === '`' || $char === '{') {
                    $contexts[] = $char;
                } elseif ($char === '}') {
                    array_pop($contexts);
                }
            } elseif ($char === '\\') {
                $i++;
            } elseif ($char === $context) {
                array_pop($contexts);
            } elseif ($context === '`' && $char === '$' && ($tag[$i + 1] ?? '') === '{') {
                // A template interpolation is an expression again, with its own strings and nested templates.
                $contexts[] = '{';
                $i++;
            }

            continue;
        }
        if ($char === '"' || $char === "'" || $char === '{') {
            $contexts[] = $char;

            continue;
        }
        if (ctype_space($char) && substr_compare($tag, $marker, $i + 1, strlen($marker)) === 0) {
            return true;
        }
    }

    return false;
}

/** A hover (or group-hover) variant that sets the text colour, e.g. hover:text-foreground, group-hover/row:text-x. */
$hoverColour = '~(?<![\w-])(?:[\w-]+:)*(?:group-)?hover(?:/[\w-]+)?:text-~';

$files = [];
foreach (['resources/js', 'addons'] as $base) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);
        if (preg_match('~\.(tsx|ts)$~', $path) !== 1 || str_contains($path, '/node_modules/') || preg_match('~\.test\.tsx?$~', $path) === 1) {
            continue;
        }
        if (str_starts_with($path, 'addons/') && ! str_contains($path, '/resources/')) {
            continue;
        }
        // Generated route helpers contain no UI.
        if (preg_match('~^resources/js/(actions|routes|wayfinder)/~', $path) === 1) {
            continue;
        }
        $files[] = $path;
    }
}
sort($files);

/** A focus-ring utility other than the shared set: ring width other than 2, any ring colour other than ring, offsets, outlines. */
$otherFocusRing = '~(?<![\w-])(?:[\w\[\]&=*-]+:)*(?:focus|focus-visible|has-focus-visible|focus-within):(?:ring-(?!2\b|ring\b(?!/)|inset\b)[\w\[\]/.-]+|outline-(?!none\b)[\w\[\]/.-]+|border-ring\b)~';
/** A ring on :focus fires on mouse clicks too; keyboard focus uses :focus-visible. */
$plainFocusRing = '~(?<![\w-])(?:[\w\[\]&=*-]+:)*focus:(?:ring|outline)-[\w\[\]/.-]+~';

$failures = [];
/** Vertical navigation remains separate; all horizontal navigation belongs to SectionTabs. */
$navigationOwners = [
    'resources/js/components/section-tabs.tsx',
    'resources/js/components/nav-main.tsx', // App sidebar: vertical primary navigation.
    'resources/js/layouts/settings/layout.tsx', // Desktop grouped sidebar; mobile uses SectionTabs.
];
/** Per allowlisted file: how many link tags carry its marker attribute. */
$markedLinkCounts = [];
/*
 * One destination, one entry (heptapod-ui-rules Navigation): the nav owners render the one registry, the user menu holds
 * identity, theme and Log out only, and the registry lists each href once. The only shared href is the footer Settings
 * entry, which opens the first settings page.
 */
foreach (['resources/js/components/nav-main.tsx', 'resources/js/layouts/settings/layout.tsx'] as $path) {
    if (! str_contains((string) file_get_contents($root.'/'.$path), "from '@/lib/navigation'")) {
        $failures[] = "{$path}: navigation owner must render entries from resources/js/lib/navigation.ts";
    }
}
$userMenu = (string) file_get_contents($root.'/resources/js/components/user-menu-content.tsx');
if (preg_match_all("~from '@/routes(?:/[^']*)?'~", $userMenu, $userMenuRoutes) !== 1 || ! str_contains($userMenu, "import { logout } from '@/routes';")) {
    $failures[] = 'resources/js/components/user-menu-content.tsx: the user menu links only to Log out; Settings and other destinations live in the sidebar';
}
$registry = (string) file_get_contents($root.'/resources/js/lib/navigation.ts');
preg_match_all("~\{\s*title: '([^']+)',\s*href: ([^,]+),[^}]*?group: '(\w+)'~s", $registry, $entries, PREG_SET_ORDER);
if (count($entries) < 10) {
    $failures[] = 'resources/js/lib/navigation.ts: could not read the navigation registry entries';
}
$registryHrefs = [];
foreach ($entries as [, $title, $href, $group]) {
    $key = $group === 'Footer' ? 'footer:'.$href : $href;
    if (isset($registryHrefs[$key])) {
        $failures[] = "resources/js/lib/navigation.ts: {$title} repeats {$href} ({$registryHrefs[$key]}); one destination, one entry";
    }
    $registryHrefs[$key] = $title;
}
foreach ($files as $path) {
    $source = file_get_contents($root.'/'.$path);
    if ($source === false) {
        throw new RuntimeException("Cannot read {$path}");
    }
    // Design fixtures feed only Vitest and the /dev/components gallery; production code renders server props, never fixture data.
    if (! str_starts_with($path, 'resources/js/fixtures/') && $path !== 'resources/js/pages/dev/components.tsx'
        // Static (from '…'), side-effect (import '…') and dynamic (import('…')) imports, aliased or relative, with or without .ts/.tsx.
        && preg_match_all('~(?:\bfrom|\bimport)\s*(?:\(\s*)?[\'"`](?:@/|(?:\.\.?/)+)(?:fixtures(?:/|(?:\.tsx?)?[\'"`])|types/design(?:\.tsx?)?[\'"`])~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [, $offset]) {
            $failures[] = sprintf('%s:%d: production code imports design fixtures; use the server props and types/monitoring', $path, substr_count($source, "\n", 0, $offset) + 1);
        }
    }
    // Inertia 3.8 useHttp resolves a 422 to undefined, so a .then/await success path would run on a refused request.
    // useHttpRequest rejects instead; it is the only place that may take useHttp from Inertia.
    if ($path !== 'resources/js/hooks/use-http-request.ts'
        && preg_match_all('~\bimport\s*\{[^}]*\buseHttp\b[^}]*\}\s*from\s*[\'"]@inertiajs/react[\'"]~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [, $offset]) {
            $failures[] = sprintf('%s:%d: useHttp from @inertiajs/react resolves a 422 as success; use useHttpRequest from @/hooks/use-http-request', $path, substr_count($source, "\n", 0, $offset) + 1);
        }
    }
    // SheetForm owns initial focus and records the opener when it opens; an autoFocus child moves focus first, so the
    // sheet can no longer return focus to its opener on close.
    if (str_contains($source, '<SheetForm') && preg_match_all('~\bautoFocus\b~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [, $offset]) {
            $failures[] = sprintf('%s:%d: autoFocus in a SheetForm file; SheetForm focuses the first field and returns focus to the opener', $path, substr_count($source, "\n", 0, $offset) + 1);
        }
    }
    if (str_contains($source, '<nav') && ! in_array($path, $navigationOwners, true)) {
        $failures[] = "{$path}: local navigation; use SectionTabs for horizontal navigation or name a vertical exception";
    }
    if ($path !== 'resources/js/components/stepper.tsx' && preg_match('~aria-current=(?:\{[^}]*)?[\'\"]step[\'\"]~s', $source) === 1) {
        $failures[] = "{$path}: local step progress; use Stepper";
    }
    // Date/time entry is the branded DateTimeField/TimeField; never a native picker (L1).
    if ($path !== 'resources/js/components/ui/date-time-field.tsx'
        && (preg_match('~type\s*=\s*(?:\{[^}]*["\'](?:date|time|datetime-local|month|week)["\']|["\'](?:date|time|datetime-local|month|week)["\'])~s', $source) === 1
            || preg_match('~["\'](?:datetime-local)["\']~', $source) === 1)) {
        $failures[] = "{$path}: native date/time input; use DateTimeField or TimeField from components/ui/date-time-field.tsx";
    }
    // Date entry uses the account or form timezone; never ask the user to type UTC (heptapod-ui-rules Copy).
    if (preg_match_all('~<(?:DateTimeField|TimeField)\b(?:[^<>]|=>)*?\stimezone=(?:"UTC"|\{\s*[\'"]UTC[\'"]\s*\})~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [, $offset]) {
            $failures[] = sprintf('%s:%d: date entry fixed to UTC; pass the account or form timezone and convert at submit (lib/date-time.ts zonedInstant)', $path, substr_count($source, "\n", 0, $offset) + 1);
        }
    }
    // Type sizes are the named tokens in app.css (heptapod-ui-rules Foundation). Primitives in components/ui may keep text-base
    // below md on fields (16 px stops iOS zoom on focus); nothing uses an arbitrary size or tracked uppercase labels.
    if (preg_match_all('~(?<![\w\[-])(?:[\w-]+:)*text-\[\d+(?:\.\d+)?(?:px|rem|em)\]~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: arbitrary font size %s; use a type token (text-page-title, text-headline, text-stat, text-section, text-sm, text-cell, text-xs, text-label, text-micro, text-mark)', $path, substr_count($source, "\n", 0, $offset) + 1, $text);
        }
    }
    if (! str_starts_with($path, 'resources/js/components/ui/')
        && preg_match_all('~(?<![\w\[-])(?:[\w-]+:)*text-(?:base|lg|[2-9]?xl)(?![\w-])~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: off-scale font size %s; use a type token', $path, substr_count($source, "\n", 0, $offset) + 1, $text);
        }
    }
    // Motion comes from the tokens and recipes in app.css / lib/motion.ts (heptapod-ui-rules Motion): no transition-all, no
    // local curves, no generic tw-animate overlay classes, no numbered durations or built-in linear/in curves.
    if ($path !== 'resources/js/lib/motion.ts' && preg_match_all('~(?<![\w-])(?:[\w-]+:)*(?:transition-all|cubic-bezier|animate-(?:in|out)|(?:zoom|slide|fade)-(?:in|out)(?:-[\w-]+)?|duration-\d+|ease-(?:linear|in)(?![\w-]))(?![\w-])~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: local motion %s; use the motion tokens and recipes (motion-popover, motion-dialog, motion-sheet, motion-overlay, duration-(--motion-*), ease-out)', $path, substr_count($source, "\n", 0, $offset) + 1, $text);
        }
    }
    // One incident page, two states (heptapod-ui-rules Incident episode): incident pages render IncidentEpisode, never the
    // site header with its site actions or a second, state-specific layout.
    if (str_starts_with($path, 'resources/js/pages/incidents/') && preg_match('~\bSiteHeader\b~', $source) === 1) {
        $failures[] = "{$path}: SiteHeader on an incident page; incidents use IncidentEpisode (one page for open and resolved)";
    }
    if ($path === 'resources/js/pages/incidents/show.tsx' && preg_match('~<IncidentEpisode\b~', $source) !== 1) {
        $failures[] = "{$path}: the incident page must render IncidentEpisode for every incident";
    }
    if ($path === 'resources/js/components/incident-episode.tsx' && preg_match('~\bSiteHeader\b~', $source) === 1) {
        $failures[] = "{$path}: SiteHeader inside the incident episode; it has its own PageHeader with the site in the trail";
    }
    // A picker trigger shows the selected value on one line (mark + label); the list row with its secondary line stays in the list.
    if ($path === 'resources/js/components/ui/combobox.tsx' && preg_match_all('~<Popover\.Trigger\b.*?</Popover\.Trigger>~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$trigger, $offset]) {
            if (preg_match('~optionContent\(|\.secondary\b|\.content\b~', $trigger) === 1) {
                $failures[] = sprintf('%s:%d: picker trigger renders the list row; show mark + label on one line (secondary only in the tooltip)', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
        }
    }
    // Rows that need action now share one emphasis, rowEmphasisClass from ui/table.tsx; no local status-coloured edge or inset bar.
    if (! str_starts_with($path, 'resources/js/components/ui/')
        && preg_match_all('~(?<![\w-])(?:[\w-]+:)*(?:shadow-\[inset_\d+px_0_0_[^\]]*\]|border-l-(?:destructive|\[var\(--(?:status-[a-z-]+|destructive)\)\]|\(--(?:status-[a-z-]+|destructive)\)))~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: local row emphasis (%s); use rowEmphasisClass from components/ui/table.tsx', $path, substr_count($source, "\n", 0, $offset) + 1, $text);
        }
    }
    // A toggle row is one hit target: SiteCheckField renders the row as the switch's label (N11, touch targets); a row action
    // (site settings Configure) sits beside that label, never inside it, so it cannot toggle the switch.
    if ($path === 'resources/js/components/site-fields.tsx'
        && (preg_match('~export function SiteCheckField\b.*?const field = \(\s*<label\s+htmlFor=\{id\}(.*?)</label>\s*\);\s*return action \? \(\s*<div\b[^>]*>\s*\{field\}\s*<div\b[^>]*>\{action\}</div>\s*</div>\s*\) : \(\s*field\s*\);~s', $source, $siteCheckField) !== 1
            || preg_match('~\{\s*action\s*\}~', $siteCheckField[1]) === 1)) {
        $failures[] = "{$path}: SiteCheckField must render the row as <label htmlFor={id}> so the row toggles the switch, with an optional action beside the label";
    }
    // One TooltipProvider at the app root: per-trigger providers break the shared skip-delay and cost a provider per hint.
    if (! in_array($path, ['resources/js/app.tsx', 'resources/js/components/ui/tooltip.tsx'], true) && preg_match('~<TooltipProvider\b~', $source) === 1) {
        $failures[] = "{$path}: local TooltipProvider; the app root owns the one provider";
    }
    // Every list footer (count + pager) is ListFooter: one phrasing and one pager look (heptapod-ui-rules Lists).
    if ($path !== 'resources/js/components/list-footer.tsx' && ! str_ends_with($path, '.d.ts') && preg_match('~[\'"](?:Previous page|Next page|Page \{\{page\}\} of \{\{lastPage\}\})[\'"]|\bcurrent_page\b[^;\n]*\blast_page\b~', $source) === 1) {
        $failures[] = "{$path}: local list pager; use ListFooter (components/list-footer.tsx)";
    }
    // Hover and focus descriptions use the shared Tooltip (TruncatedText / TooltipHint), never a native title tooltip. <iframe>
    // needs its title as the accessible frame name; <abbr> expands an abbreviation.
    if (str_ends_with($path, '.tsx') && preg_match_all('~<([a-z][\w-]*|Link|Button|Badge)(?=[\s>/])~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        // DOM elements and the components that forward title straight to one.
        foreach ($matches[1] as [$tag, $offset]) {
            if ($tag === 'iframe' || $tag === 'abbr') {
                continue;
            }
            $depth = 0;
            $quote = null;
            $length = strlen($source);
            $attributes = '';
            for ($i = $offset + strlen($tag); $i < $length; $i++) {
                $char = $source[$i];
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }
                    $attributes .= ' ';

                    continue;
                }
                if ($depth > 0) {
                    $depth += $char === '{' ? 1 : ($char === '}' ? -1 : 0);
                    $attributes .= ' ';

                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '{') {
                    $depth = 1;
                } elseif ($char === '>') {
                    break;
                }
                $attributes .= $char;
            }
            if (preg_match('~\stitle=~', $attributes) === 1) {
                $failures[] = sprintf('%s:%d: native title tooltip on <%s>; use TruncatedText or TooltipHint (components/truncated-text.tsx)', $path, substr_count($source, "\n", 0, $offset) + 1, $tag);
            }
        }
    }
    if (str_ends_with($path, '.tsx') && preg_match_all('~(?<=[\s"\'`:])uppercase(?=[\s"\'`])~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: uppercase label; labels use sentence case', $path, substr_count($source, "\n", 0, $offset) + 1);
        }
    }
    // A manual refresh is PageHeader's `refresh` action (heptapod-ui-rules Pages): no floating, inline or under-table refresh
    // button. Named exception: login's "Refresh session" recovers an expired CSRF session, it reloads no page data.
    if ($path !== 'resources/js/components/page-header.tsx'
        && preg_match_all('~[\'"`](Refresh(?:ing…| (?!session\b)[a-z]+)?)[\'"`]~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[1] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: local refresh action "%s"; pass PageHeader refresh={{ onRefresh, refreshing }}', $path, substr_count($source, "\n", 0, $offset) + 1, $text);
        }
    }
    // One Clear filters per screen (heptapod-ui-rules Filters): FilterBar renders it while a filter differs from its default; the
    // filtered EmptyState and page toolbars only point to it.
    if ($path !== 'resources/js/components/filter-bar.tsx' && preg_match_all('~[\'"]Clear filters[\'"]~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [, $offset]) {
            $failures[] = sprintf('%s:%d: local Clear filters; pass FilterBar filtersActive and onClearFilters', $path, substr_count($source, "\n", 0, $offset) + 1);
        }
    }
    // Overflow names the whole set ("Show all N") or reads as prose ("and N more"); never a "+N more" counter (heptapod-ui-rules Copy).
    if (preg_match('~[\'"`>]\s*\+\s*(?:\d+|\{[^}]+\}|\$\{[^}]+\})\s*(?:more|weitere?)\b~i', $source) === 1) {
        $failures[] = "{$path}: \"+N more\" text; link \"Show all N\" to the full list or write \"and N more\"";
    }
    // Callouts are the toned Alert (heptapod-ui-rules Callouts): the tone owns colour, so callers pass no className, and no
    // hand-built box tints itself with a status token or a raw palette colour (hover tints on buttons are not callouts).
    if (preg_match_all('~<Alert(?=\s)(?:[^<>]|=>)*?\s(className|variant)=~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[1] as [$attribute, $offset]) {
            $failures[] = sprintf('%s:%d: Alert %s; pick a tone (info, warning, danger) and let the parent own spacing', $path, substr_count($source, "\n", 0, $offset) + 1, $attribute);
        }
    }
    if (! str_starts_with($path, 'resources/js/components/ui/') && ! isset($calloutTintExceptions[$path])
        && preg_match_all('~(?<![\w:/-])(?:bg|border)-(?:(?:red|amber|yellow|orange|green|emerald|rose|lime)-\d+|\(--status-[a-z]+\)/\d+|\[var\(--status-[a-z]+\)\]/\d+|destructive/\d+)~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: hand-tinted callout (%s); use Alert with a tone', $path, substr_count($source, "\n", 0, $offset) + 1, $text);
        }
    }
    // Status text uses the tone tokens (Alert, InputError, status colours); never a raw palette text colour such as the old
    // auth `text-green-600` status line (heptapod-ui-rules Callouts). Variant prefixes (dark:, hover:) count too.
    if (! str_starts_with($path, 'resources/js/components/ui/')
        && preg_match_all('~(?<![\w/-])(?:[\w-]+:)*text-(?:red|amber|yellow|orange|green|emerald|rose|lime|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink)-\d+~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: raw palette text colour (%s); use Alert, InputError or a status token', $path, substr_count($source, "\n", 0, $offset) + 1, $text);
        }
    }
    // Loaders are the shared Spinner (labelled, reduced-motion aware); never a local spinning icon. Named exception: the
    // PageHeader Refresh icon turns while refreshing (the action's own icon, not a loader), and only that one occurrence.
    if (! str_starts_with($path, 'resources/js/components/ui/')
        && preg_match_all('~(?<![\w-])(?:[\w-]+:)*animate-spin\b~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        $refreshIcon = $path === 'resources/js/components/page-header.tsx' && count($matches[0]) === 1
            && preg_match('~<RefreshCw\b[^>]*\bclassName=\{\s*refresh\.refreshing\s*\?\s*[\'"]animate-spin[\'"]~s', $source) === 1;
        foreach ($refreshIcon ? [] : $matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: local spinning icon (%s); use Spinner', $path, substr_count($source, "\n", 0, $offset) + 1, $text);
        }
    }
    // Page notices go into PageHeader's notices slot, never above the h1: in a page component, no Alert before its PageHeader.
    $pageStart = strpos($source, 'export default function');
    if ($pageStart !== false) {
        $page = substr($source, $pageStart);
        $headerAt = strpos($page, '<PageHeader');
        if ($headerAt !== false && preg_match('~<Alert[\s>]~', $page, $match, PREG_OFFSET_CAPTURE) === 1 && $match[0][1] < $headerAt) {
            $failures[] = sprintf('%s:%d: page notice above the PageHeader; pass it through PageHeader notices', $path, substr_count($source, "\n", 0, $pageStart + $match[0][1]) + 1);
        }
    }
    // An irreversible confirmation is red: a ConfirmDialog whose label (or its copy table) uses a removal verb passes destructive.
    if (preg_match_all('~<ConfirmDialog(?=\s)(?:=>|[^<>])*?(?<!=)>~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$tag, $offset]) {
            $removal = preg_match('~confirmLabel=\{t\(\s*[\'"](?:Delete|Remove|Revoke|Archive|Skip)\b~', $tag) === 1
                || (str_contains($tag, 'confirmationCopy') && preg_match("~label: '(?:Delete|Remove|Revoke|Archive|Skip)\\b~", $source) === 1);
            if ($removal && preg_match('~\sdestructive(?:[\s=/>])~', $tag) !== 1) {
                $failures[] = sprintf('%s:%d: irreversible ConfirmDialog without destructive', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
        }
    }
    // An empty list shows only its EmptyState (heptapod-ui-rules Lists): a column header whose list holds a bare EmptyState (the
    // next EmptyState within 1500 characters, before another header) renders only in a branch (`&& (` or `? (`).
    if (preg_match_all('~<(?:ListHeader|TableHeader)[\s>]~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [, $offset]) {
            $after = substr($source, $offset + 1, 1500);
            $empty = strpos($after, '<EmptyState');
            $nextHeader = preg_match('~<(?:ListHeader|TableHeader)[\s>]~', $after, $next, PREG_OFFSET_CAPTURE) === 1 ? $next[0][1] : PHP_INT_MAX;
            if ($empty !== false && $empty < $nextHeader
                && preg_match('~(?:&&|\?)\s*\(\s*$~', substr($source, max(0, $offset - 200), min(200, $offset))) !== 1) {
                $failures[] = sprintf('%s:%d: list header renders above an empty list; render it only when there are rows', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
        }
    }
    // Create and edit of a record use SheetForm: no hand-built sheet body, header padding or footer.
    if (! isset($sheetContentOwners[$path]) && preg_match('~<SheetContent[\s>]~', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
        $failures[] = sprintf('%s:%d: hand-built sheet; use SheetForm', $path, substr_count($source, "\n", 0, $match[0][1]) + 1);
    }
    // Native form controls (heptapod-ui-rules Native controls): the branded primitives own every checkbox, radio, number,
    // disclosure and select; forms never show browser validation bubbles; inline errors render through InputError.
    if (! str_starts_with($path, 'resources/js/components/ui/')) {
        $nativeControls = [
            '~type\s*=\s*(?:\{\s*)?["\'](?:checkbox|radio)["\']~' => 'native checkbox or radio; use Checkbox/CheckboxField, RadioGroup, RadioCards or WeekdayToggleGroup',
            '~type\s*=\s*(?:\{\s*)?["\']number["\']~' => 'native number input; use NumberField',
            '~<(?:details|summary)(?=[\s>])~' => 'native disclosure; use Disclosure',
            '~<(?:select|datalist)(?=[\s>])~' => 'native select or datalist; use Select or the Combobox family',
            '~<(?:input|textarea)(?=[\s>/])~' => 'raw input or textarea; use Input, PasswordInput or Textarea so the field keeps the shared focus ring',
        ];
        foreach ($nativeControls as $pattern => $message) {
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [, $offset]) {
                    $failures[] = sprintf('%s:%d: %s', $path, substr_count($source, "\n", 0, $offset) + 1, $message);
                }
            }
        }
        $rawButtons = preg_match_all('~<button(?=[\s>])~', $source, $matches, PREG_OFFSET_CAPTURE);
        $namedRawButtons = $rawButtonOwners[$path][0] ?? 0;
        if ($rawButtons !== $namedRawButtons) {
            $failures[] = sprintf('%s:%d: %d raw <button> element(s), %d named in $rawButtonOwners; use Button', $path, substr_count($source, "\n", 0, $matches[0][0][1] ?? 0) + 1, $rawButtons, $namedRawButtons);
        }
        if (preg_match_all('~<(?:form|Form)(?=\s|>)(?:[^<>]|=>)*?>~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$text, $offset]) {
                if (! tagHasAttribute($text, 'noValidate')) {
                    $failures[] = sprintf('%s:%d: form without noValidate; browser validation bubbles are off and errors use InputError', $path, substr_count($source, "\n", 0, $offset) + 1);
                }
            }
        }
        // A field's validation error (errors.x / form.errors.x) is tied to its control: InputError `field` (or `id`) plus
        // fieldErrorProps on the control. An error key that a button action returns (no field holds it) is named in
        // $actionErrorKeys with its file and reason; nothing is exempt by key alone.
        if (preg_match_all('~<InputError\b((?:[^<>]|=>)*?)/>~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[1] as [$attributes, $offset]) {
                if (preg_match('~\bmessage=\{\s*(?:form\.|\w+Form\.)?errors\??\.(\w+)\s*\}~', $attributes, $message) === 1
                    && ($actionErrorKeys[$path] ?? null) !== $message[1]
                    && preg_match('~\b(?:field|id)=~', $attributes) !== 1) {
                    $failures[] = sprintf('%s:%d: field error %s without field=; pass the control id to InputError and spread fieldErrorProps on the control', $path, substr_count($source, "\n", 0, $offset) + 1, $message[1]);
                }
            }
        }
        if ($path !== 'resources/js/components/input-error.tsx' && preg_match_all('~<p(?=\s)(?:[^<>]|=>)*?className=~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$text, $offset]) {
                $value = expressionValue($source, $offset + strlen($text));
                if (preg_match('~(?<![\w-])text-(?:destructive|red-\d+)\b~', $value) !== 1) {
                    continue;
                }
                if (isset($destructiveTextExceptions[$path]) && str_contains($value, $destructiveTextExceptions[$path][0])) {
                    $destructiveTextExceptionCounts[$path] = ($destructiveTextExceptionCounts[$path] ?? 0) + 1;

                    continue;
                }
                $failures[] = sprintf('%s:%d: hand-rolled error text; use InputError', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
        }
        if (preg_match_all('~<(?:Input|PasswordInput|SelectTrigger)(?=\s)(?:[^<>]|=>)*?className=~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$text, $offset]) {
                if (preg_match('~(?<![\w-])(?:[\w-]+:)*h-\[\d+px\]~', expressionValue($source, $offset + strlen($text))) === 1) {
                    $failures[] = sprintf('%s:%d: off-token control height; Input and SelectTrigger own the 32/40 px height', $path, substr_count($source, "\n", 0, $offset) + 1);
                }
            }
        }
    }
    if (str_starts_with($path, 'resources/js/pages/sites/') && str_contains($source, '<SectionTabs')) {
        $failures[] = "{$path}: local site sections; use SiteTabs for consistent check markers";
    }
    // Global keyboard shortcuts register in lib/shortcuts.ts and appear in the ? sheet; ⌘K and ⌘B are the listed primitives.
    if (preg_match('~addEventListener\(\s*[\'"]keydown~', $source) === 1 && ! in_array($path, ['resources/js/lib/shortcuts.ts', 'resources/js/components/real-command-palette.tsx', 'resources/js/components/ui/sidebar.tsx'], true)) {
        $failures[] = "{$path}: global keydown listener; register shortcuts in resources/js/lib/shortcuts.ts so the ? sheet lists them";
    }
    // One label per action (heptapod-ui-rules Navigation): retired synonyms must not return.
    if (preg_match("~'(?:Run now|Run check now|View incident|View check|Follow \\(personal\\)|Unfollow \\(personal\\))(?:: \\{\\{check\\}\\})?'~", $source, $retired) === 1) {
        $failures[] = "{$path}: retired action label {$retired[0]}; use Run check, Open incident, Open check, Follow or Unfollow";
    }
    if (preg_match('~(?<![\\w-])cursor-(?:default|[ew]-resize)\\b~', $source) === 1) {
        $failures[] = "{$path}: enabled control cursor override; use the app-wide pointer rule";
    }
    if (preg_match_all('~(?<![\w.])(?:window\s*\.\s*)?(confirm|alert|prompt)\s*\(~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: native %s; use ConfirmDialog', $path, substr_count($source, "\n", 0, $offset) + 1, trim($text, " (\t"));
        }
    }
    if (preg_match_all('~<(Button|SidebarMenuButton)(?=\s)(?:[^<>]|=>)*?\bsize=~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $index => [$text, $offset]) {
            $value = expressionValue($source, $offset + strlen($text));
            if (preg_match('~[\'\"]lg[\'\"]~', $value) !== 1) {
                continue;
            }
            if ($matches[1][$index][0] === 'SidebarMenuButton' && isset($largeSidebarButtonExceptions[$path])) {
                $largeSidebarButtonExceptionCounts[$path] = ($largeSidebarButtonExceptionCounts[$path] ?? 0) + 1;

                continue;
            }
            $failures[] = sprintf('%s:%d: large button; use the compact default size', $path, substr_count($source, "\n", 0, $offset) + 1);
        }
    }
    // Density belongs to the shared table/list tokens, including responsive cards that keep native link/list semantics.
    if ($path !== 'resources/js/components/ui/table.tsx') {
        // Scan cn calls independently of JSX so named classes and class-producing functions cannot hide overrides.
        if (preg_match_all('~\\bcn\\s*\\(~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$text, $offset]) {
                $value = expressionValue($source, $offset + strlen($text) - 1);
                if (preg_match($densityToken, $value) === 1 && preg_match($densityOverride, $value) === 1) {
                    $failures[] = sprintf('%s:%d: density token combined with local row height or vertical padding', $path, substr_count($source, "\n", 0, $offset) + 1);
                }
            }
        }
        if (preg_match_all('~<([A-Za-z][\\w.]*)(?=\\s)(?:[^<>]|=>)*?className=~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $index => [$text, $offset]) {
                $value = expressionValue($source, $offset + strlen($text));
                $tag = $matches[1][$index][0];
                // Guard all native list items rather than trying to infer JSX ancestry across map callbacks.
                $timeline = $tag === 'li' && ($timelineRowClasses[$path] ?? null) === $value;
                $stepper = $path === 'resources/js/components/stepper.tsx' && $tag === 'li' && tagHasAttribute($text, 'aria-current=');
                $primitive = in_array($tag, ['TableRow', 'TableCell', 'TableHead', 'ListRow', 'ListHeader', 'li'], true);
                $usesDensity = preg_match($densityToken, $value) === 1;
                if (! $timeline && ! $stepper && ($primitive || $usesDensity) && preg_match($densityOverride, $value) === 1) {
                    $failures[] = sprintf('%s:%d: local row height or vertical padding; use the shared table/list density', $path, substr_count($source, "\n", 0, $offset) + 1);
                }
            }
        }
        if (preg_match('~<(?:table|tr|td|th)(?=\\s|>)~', $source) === 1) {
            $failures[] = "{$path}: raw table element; use the shared Table primitives";
        }
    }
    // Site forms share the authorized, always-visible client creation action and picker.
    if ($path !== 'resources/js/components/site-fields.tsx' && preg_match('~\bid=[\'"]site-client[\'"]~', $source) === 1) {
        $failures[] = "{$path}: local site client picker; use SiteClientField";
    }
    // The named presets own display precision. Calendar keys, exports and datetime-local values are machine formats.
    $dateTimeOwners = [
        'resources/js/hooks/use-format.ts',
        'resources/js/lib/date-time.ts',
        'resources/js/lib/monitor-time.ts',
        'resources/js/lib/upcoming-calendar.ts',
        'resources/js/lib/findings-export.ts',
        'resources/js/pages/maintenance/index.tsx',
        'resources/js/components/ui/date-time-field.tsx', // Calendar grid and wall-time preview are purpose-specific formats.
        'resources/js/components/timezone-combobox.tsx', // UTC-offset computation for picker rows, not a display timestamp.
    ];
    if (! in_array($path, $dateTimeOwners, true) && preg_match('~new\\s+Intl\\.DateTimeFormat\\b~', $source) === 1) {
        $failures[] = "{$path}: local date formatter; use the shared date/time presets";
    }
    if ($path !== 'resources/js/lib/date-time.ts' && $path !== 'resources/js/components/ui/date-time-field.tsx' && preg_match('~\\btimeStyle\\s*:~', $source) === 1) {
        $failures[] = "{$path}: local clock precision; use the shared date/time presets";
    }
    if (! in_array($path, $dateTimeOwners, true)) {
        foreach (['~\\b(?:date|dateRange)\\s*\\(~', '~\\.toLocale(?:Date|Time)?String\\s*\\(~'] as $pattern) {
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[0] as [$text, $offset]) {
                $call = expressionValue($source, $offset + strlen($text) - 1);
                if (! str_contains($text, 'toLocale') && preg_match('~\\b(?:hour|minute|second|hour12)\\s*:~', $call) !== 1) {
                    continue;
                }
                if (isset($dateTimeCallExceptions[$path]) && str_contains((string) preg_replace('~\s+~', ' ', $call), $dateTimeCallExceptions[$path][0])) {
                    $dateTimeCallExceptionCounts[$path] = ($dateTimeCallExceptionCounts[$path] ?? 0) + 1;

                    continue;
                }
                $failures[] = sprintf('%s:%d: local display-time options or locale formatter; use the shared date/time presets', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
        }
    }
    if (preg_match_all('~<h[2-6](?=\s|>)[^>]*>~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $exception = $sectionHeadingExceptions[$path] ?? null;
            if ($exception !== null && str_contains($text, $exception[0])) {
                $sectionHeadingExceptionCounts[$path] = ($sectionHeadingExceptionCounts[$path] ?? 0) + 1;

                continue;
            }
            $failures[] = sprintf('%s:%d: local section heading; use SectionHeading', $path, substr_count($source, "\n", 0, $offset) + 1);
        }
    }
    if (preg_match_all('~<(SectionHeading|CardTitle)(?=\s)(?:[^<>]|=>)*?className=~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $value = expressionValue($source, $offset + strlen($text));
            if (preg_match('~\b(?:text-(?!(?:center|left|right|justify|start|end)\b)|font-|leading-|tracking-)~', $value) === 1) {
                $failures[] = sprintf('%s:%d: section typography override; use the shared sectionHeadingClass token', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
        }
    }
    // StatusDot owns compact state shapes; a tiny filled circle cannot distinguish OK, Warning and Check error.
    if (preg_match_all('~<(?:span|div)(?=\s)(?:[^<>]|=>)*?className=~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $value = expressionValue($source, $offset + strlen($text));
            $smallSquare = preg_match('~\bsize-(?:1\.5|2)(?![\w.-])~', $value) === 1
                || (preg_match('~\bh-(?:1\.5|2)(?![\w.-])~', $value) === 1 && preg_match('~\bw-(?:1\.5|2)(?![\w.-])~', $value) === 1);
            if ($smallSquare && str_contains($value, 'rounded-full') && str_contains($value, 'bg-')) {
                if (isset($filledDotExceptions[$path]) && str_contains($value, $filledDotExceptions[$path][0])) {
                    $filledDotExceptionCounts[$path] = ($filledDotExceptionCounts[$path] ?? 0) + 1;

                    continue;
                }
                $failures[] = sprintf('%s:%d: local filled status dot; use StatusDot', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
        }
    }
    $focusException = $focusRingAllowlist[$path][0] ?? null;
    $focusExceptionHits = 0;
    foreach ([$otherFocusRing, $plainFocusRing] as $pattern) {
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$text, $offset]) {
                if ($text === $focusException) {
                    $focusExceptionHits++;

                    continue;
                }
                $failures[] = sprintf('%s:%d: focus ring %s; use focus-visible:ring-2 focus-visible:ring-ring (optionally ring-inset)', $path, substr_count($source, "\n", 0, $offset) + 1, $text);
            }
        }
    }
    // Removing the browser outline without drawing the shared ring leaves keyboard focus invisible.
    if (! str_starts_with($path, 'resources/js/components/ui/')
        && preg_match_all('~(["\'`])((?:(?!\1).)*?(?<![\w-])focus-visible:outline-none(?![\w-])(?:(?!\1).)*)\1~', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            if (preg_match('~(?<![\w-])focus-visible:ring-2(?![\w-])~', $match[2][0]) !== 1) {
                $failures[] = sprintf('%s:%d: focus-visible:outline-none without the focus ring leaves keyboard focus invisible; use focusRingClass', $path, substr_count($source, "\n", 0, $match[0][1]) + 1);
            }
        }
    }
    if ($focusException !== null && $focusExceptionHits !== 1) {
        $failures[] = "{$path}: the focus-ring exception ({$focusException}) must occur exactly once, found {$focusExceptionHits}";
    }
    if (! isset($underlineAllowlist[$path]) && preg_match_all('~\[data-link-text\]~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as [$text, $offset]) {
            $failures[] = sprintf('%s:%d: styles the link text span from outside; use a TextLink/TextLinkButton prop (e.g. truncate)', $path, substr_count($source, "\n", 0, $offset) + 1);
        }
    }
    if (isset($underlineAllowlist[$path])) {
        // The primitives own the link look: they may underline, but never recolour on hover.
        if (preg_match_all($hoverColour, $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$text, $offset]) {
                $failures[] = sprintf('%s:%d: link hover changes the text colour; hover only removes the underline', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
        }

        continue;
    }
    // An opening <Link …> or <a …> tag (attributes may span lines) whose className carries an underline utility.
    // The className value is read whole: a quoted string, or a {…} expression with nested braces and template literals.
    // A whitespace after the tag name: <Link.Panel> or <Linkish> is a different component, not a link.
    if (preg_match_all('~<(Link|a)(?=\s)(?:[^<>]|=>)*?className=~s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        $hoverException = $hoverColourAllowlist[$path][0] ?? null;
        $markedTags = $hoverException === null ? 0 : count(array_filter($matches[0], static fn (array $match): bool => tagHasAttribute($match[0], $hoverException)));
        if ($hoverException !== null) {
            $markedLinkCounts[$path] = $markedTags;
        }
        foreach ($matches[0] as [$text, $offset]) {
            $value = expressionValue($source, $offset + strlen($text));
            if (preg_match('~(?<![\w-])(?:[\w-]+:)*underline\b~', $value) === 1) {
                $failures[] = sprintf('%s:%d: hand-styled link underline; use TextLink or EntityLink', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
            $exempt = $hoverException !== null && $markedTags === 1 && tagHasAttribute($text, $hoverException);
            if (! $exempt && preg_match($hoverColour, $value) === 1) {
                $failures[] = sprintf('%s:%d: link hover changes the text colour; hover only removes the underline', $path, substr_count($source, "\n", 0, $offset) + 1);
            }
        }
    }
}

foreach ($largeSidebarButtonExceptions as $path => $reason) {
    $found = $largeSidebarButtonExceptionCounts[$path] ?? 0;
    if ($found !== 1) {
        $failures[] = "{$path}: expected one named large sidebar identity control, found {$found} ({$reason})";
    }
}

foreach ($filledDotExceptions as $path => [$snippet, $reason]) {
    $found = $filledDotExceptionCounts[$path] ?? 0;
    if ($found !== 1) {
        $failures[] = "{$path}: expected one named decorative-dot exception ({$snippet}), found {$found} ({$reason})";
    }
}

foreach ($destructiveTextExceptions as $path => [$snippet, $reason]) {
    $found = $destructiveTextExceptionCounts[$path] ?? 0;
    if ($found !== 1) {
        $failures[] = "{$path}: expected one named destructive-text exception ({$snippet}), found {$found} ({$reason})";
    }
}

foreach ($dateTimeCallExceptions as $path => [$snippet, $reason]) {
    $found = $dateTimeCallExceptionCounts[$path] ?? 0;
    if ($found !== 1) {
        $failures[] = "{$path}: expected one named date/time call exception ({$snippet}), found {$found} ({$reason})";
    }
}

foreach ($sectionHeadingExceptions as $path => [$marker, $count, $reason]) {
    $found = $sectionHeadingExceptionCounts[$path] ?? 0;
    if ($found !== $count) {
        $failures[] = "{$path}: expected {$count} named raw-heading exceptions, found {$found} ({$reason})";
    }
}

// Each hover exception covers exactly one named element: one link tag with the marker attribute, never zero, never several.
foreach ($hoverColourAllowlist as $path => [$marker, $reason]) {
    $found = $markedLinkCounts[$path] ?? 0;
    if ($found === 0) {
        $failures[] = "{$path}: allowlisted element ({$marker}) no longer exists ({$reason})";
    } elseif ($found > 1) {
        $failures[] = "{$path}: the hover exception ({$marker}) must mark exactly one link, found {$found}; the exception covers one named element";
    }
}
foreach ($sheetContentOwners as $path => $reason) {
    if (! is_file($root.'/'.$path)) {
        $failures[] = "{$path}: sheet exception no longer exists ({$reason})";
    }
}
foreach ($calloutTintExceptions as $path => $reason) {
    if (! is_file($root.'/'.$path)) {
        $failures[] = "{$path}: callout-tint exception no longer exists ({$reason})";
    }
}
foreach ($underlineAllowlist as $path => $reason) {
    if (! is_file($root.'/'.$path)) {
        $failures[] = "{$path}: allowlisted file no longer exists ({$reason})";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "UI primitive guard failed:\n  ".implode("\n  ", $failures)."\n");
    exit(1);
}
passthru('cd '.escapeshellarg($root).' && node scripts/check-form-selects.mjs', $formSelectStatus);
if ($formSelectStatus !== 0) {
    exit($formSelectStatus);
}
printf("UI primitives: %d files checked; 0 native prompts, 0 hand-styled link underlines, 0 link hover colour changes, 0 other focus rings, 0 local filled status dots, 0 local section titles or typography overrides, 0 local display-time presets, 0 large action buttons, 0 local horizontal navigation or step progress, 0 enabled cursor overrides, 0 local row density overrides or raw tables, 0 native date/time inputs, 0 hand-tinted callouts or notices above the page header, 0 raw palette text colours, 0 local spinners, 0 irreversible confirmations without destructive, 0 native checkbox/radio/number/details/select controls or raw inputs/textareas/buttons, 0 forms with browser validation, 0 hand-rolled error lines, 0 off-token control heights, 0 arbitrary or off-scale font sizes, 0 native title tooltips, 0 local list pagers, 0 local motion, 0 local tooltip providers, 0 site headers or second layouts on incident pages, 0 multi-line picker triggers, 0 local row emphasis, 0 switch rows without a row label.\n", count($files));

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-appearance="{{ request()->cookie('appearance', 'system') }}" @class(['dark' => request()->cookie('appearance') === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('errors.'.$status.'.title') }} · Heptapod</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @vite('resources/css/app.css')
        <style>
            @media (prefers-color-scheme: dark) {
                html:not([data-appearance="light"]):not(.dark) {
                    color-scheme: dark;
                    --background: #0d1013;
                    --foreground: #e6e9ec;
                    --muted-foreground: #a2abb4;
                    --primary: #e6e9ec;
                    --primary-foreground: #0d1013;
                    --ring: #a9b7c4;
                }
            }
        </style>
    </head>
    <body class="bg-background font-sans text-foreground antialiased">
        <main class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col items-center gap-6 text-center">
                {{-- The static AppLogo lockup uses the same mark geometry and wordmark CSS without booting React. --}}
                <span class="hp-brand [--brand-mark-h:28px]" role="img" aria-label="Heptapod">
                    <svg class="hp-mark" data-state="still" viewBox="33.5 19.5 53 81" aria-hidden="true" focusable="false">
                        <rect class="hp-seg hp-a" rx="6.5" x="49" y="19.5" width="22" height="13" />
                        <rect class="hp-seg hp-b" data-lit rx="6.5" x="73.5" y="28.5" width="13" height="29" />
                        <rect class="hp-seg hp-c" data-lit rx="6.5" x="73.5" y="62.5" width="13" height="29" />
                        <rect class="hp-seg hp-d" rx="6.5" x="49" y="87.5" width="22" height="13" />
                        <rect class="hp-seg hp-e" data-lit rx="6.5" x="33.5" y="62.5" width="13" height="29" />
                        <rect class="hp-seg hp-f" data-lit rx="6.5" x="33.5" y="28.5" width="13" height="29" />
                        <rect class="hp-seg hp-g" data-lit rx="6.5" x="49" y="53.5" width="22" height="13" />
                    </svg>
                    <span class="hp-word" aria-hidden="true"></span>
                </span>
                <div class="space-y-2">
                    <p class="font-mono text-sm text-muted-foreground">{{ $status }}</p>
                    <h1 class="text-page-title">{{ __('errors.'.$status.'.title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ __('errors.'.$status.'.description') }}</p>
                </div>
                {{-- The classes of Button variant default / size default (components/ui/button.tsx): 40 px below sm, 32 px from sm,
                     13 px label, the shared 2 px focus ring and press; plain CSS so the page needs no JavaScript. --}}
                <a href="{{ $recoveryUrl }}" class="inline-flex h-10 min-w-10 shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-full bg-primary px-3 text-cell font-medium text-primary-foreground outline-none transition-[color,background-color,border-color,box-shadow,scale] duration-(--motion-press) ease-out hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.97] sm:h-8 sm:min-w-0">
                    {{ __($recoveryLabel) }}
                </a>
            </div>
        </main>
    </body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @if (config('broadcasting.default') === 'reverb' && is_string(config('broadcasting.connections.reverb.key')) && config('broadcasting.connections.reverb.key') !== '')
            <meta name="heptapod-reverb" content="{{ json_encode([
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.reverb_client.host'),
                'port' => config('broadcasting.reverb_client.port') === null ? null : (int) config('broadcasting.reverb_client.port'),
                'scheme' => config('broadcasting.reverb_client.scheme'),
                'path' => config('broadcasting.reverb_client.path') ?? '',
            ], JSON_THROW_ON_ERROR) }}">
        @endif

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #e8ebed;
            }

            html.dark {
                background-color: #0d1013;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>Heptapod</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>

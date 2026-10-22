@props(['title' => 'CRM — for consultants, freelancers and small sales teams'])
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }}</title>
        <meta name="description" content="Keep every contact and company organized, move deals through your pipeline, follow up on time and see what you're owed.">
        <link rel="canonical" href="{{ url('/') }}">
        <meta name="robots" content="index,follow">
        <meta name="theme-color" content="#2563eb">
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="preload" href="/fonts/landing/inter-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
        <script>(() => { let theme = null; try { theme = localStorage.getItem('theme'); } catch (e) {} if (theme === 'dark' || ((theme === null || theme === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches)) { document.documentElement.classList.add('dark'); } })();</script>
        @vite(['resources/css/landing.css', 'resources/js/landing.js'])
    </head>
    <body class="min-h-screen bg-white font-sans text-gray-900 antialiased selection:bg-primary-200 selection:text-primary-950 dark:bg-gray-950 dark:text-gray-100">
        <div class="relative overflow-x-clip">
            <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[42rem] bg-[radial-gradient(circle_at_top,rgba(37,99,235,.18),transparent_52%)] dark:bg-[radial-gradient(circle_at_top,rgba(96,165,250,.18),transparent_52%)]"></div>
            {{ $slot }}
        </div>
    </body>
</html>

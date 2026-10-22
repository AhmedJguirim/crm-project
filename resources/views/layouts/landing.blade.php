@props(['title' => 'CRM — for consultants, freelancers and small sales teams', 'description' => "Keep every contact and company organized, move deals through your pipeline, follow up on time and see what you're owed."])
@php
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'CRM',
        'description' => $description,
        'url' => url('/'),
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }}</title>
        <meta name="description" content="{{ $description }}">
        <link rel="canonical" href="{{ url('/') }}">
        <meta name="robots" content="index,follow">
        <meta name="theme-color" content="#2563eb">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="CRM">
        <meta property="og:locale" content="en_US">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ url('/') }}">
        <meta property="og:image" content="{{ asset('images/landing/og.png') }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="CRM: your clients, deals and invoices, in one place.">
        <meta name="twitter:card" content="summary_large_image">
        <script type="application/ld+json">@json($structuredData)</script>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="preload" href="/fonts/landing/inter-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
        <script>(() => { let theme = null; try { theme = localStorage.getItem('theme'); } catch (e) {} if (theme === 'dark' || ((theme === null || theme === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches)) { document.documentElement.classList.add('dark'); } })();</script>
        @vite(['resources/css/landing.css', 'resources/js/landing.js'])
    </head>
    <body class="min-h-screen bg-white font-sans text-gray-900 antialiased selection:bg-primary-200 selection:text-primary-950 dark:bg-gray-950 dark:text-gray-100">
        <a href="#main" class="sr-only rounded-full bg-white px-4 py-2 text-sm font-semibold text-primary-700 shadow-lg focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-gray-900 dark:text-primary-300">Skip to content</a>
        <div class="relative overflow-x-clip">
            <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[42rem] bg-[radial-gradient(circle_at_top,rgba(37,99,235,.18),transparent_52%)] dark:bg-[radial-gradient(circle_at_top,rgba(96,165,250,.18),transparent_52%)]"></div>
            {{ $slot }}
        </div>
    </body>
</html>

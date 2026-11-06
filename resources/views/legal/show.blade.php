@php
    $panel = \Filament\Facades\Filament::getPanel('admin');
    $loginUrl = $panel->getLoginUrl();
    $registerUrl = $panel->getRegistrationUrl();
    $appUrl = auth()->check() ? $panel->getUrl() : null;
@endphp
<x-layouts::landing :title="$title.' — CRM'" :description="$description" :canonical="$canonical" :preload-hero="false">
    <header class="landing-container py-6">
        <a href="{{ route('home') }}" class="rounded-md text-xl font-black text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:text-white">CRM</a>
    </header>

    <main id="main" tabindex="-1" class="outline-none">
        <article class="landing-container max-w-3xl py-12">
            <h1 class="text-3xl font-black tracking-tight sm:text-4xl">{{ $title }}</h1>
            <div class="[&_h2]:mt-10 [&_h2]:text-xl [&_h2]:font-bold [&_h3]:mt-6 [&_h3]:font-semibold [&_p]:mt-4 [&_p]:leading-7 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6 [&_li]:mt-2 [&_a]:text-primary-600 [&_a]:underline dark:[&_a]:text-primary-300">
                {{ $body }}
            </div>
        </article>
    </main>

    <x-landing.footer :login-url="$loginUrl" :register-url="$registerUrl" :app-url="$appUrl" :home-url="route('home')" />
</x-layouts::landing>

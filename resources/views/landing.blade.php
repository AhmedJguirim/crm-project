@php
    $panel = \Filament\Facades\Filament::getPanel('admin');
    $loginUrl = $panel->getLoginUrl();
    $registerUrl = $panel->getRegistrationUrl();
    $appUrl = auth()->check() ? $panel->getUrl() : null;
@endphp
<x-layouts::landing>
    <x-landing.navbar :login-url="$loginUrl" :register-url="$registerUrl" :app-url="$appUrl" />

    <main id="main">
        <x-landing.hero :login-url="$loginUrl" :register-url="$registerUrl" :app-url="$appUrl" />
        <x-landing.facts />
        <x-landing.features />
    </main>

    <x-landing.footer :login-url="$loginUrl" :register-url="$registerUrl" :app-url="$appUrl" />
</x-layouts::landing>

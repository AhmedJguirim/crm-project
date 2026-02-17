# Source: https://filamentphp.com/docs/4.x/components/breadcrumbs

Components 

# Breadcrumbs Blade component 

## [#](#introduction)Introduction

The breadcrumbs component is used to render a simple, linear navigation that informs the user of their current location within the application:

 <x-filament::breadcrumbs :breadcrumbs="[
 '/' => 'Home',
 '/dashboard' => 'Dashboard',
 '/dashboard/users' => 'Users',
 '/dashboard/users/create' => 'Create User',
 ]" />

The keys of the array are URLs that the user is able to click on to navigate, and the values are the text that will be displayed for each link.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-breadcrumbs.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
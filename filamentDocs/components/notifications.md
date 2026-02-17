# Source: https://filamentphp.com/docs/4.x/components/notifications

Components 

# Rendering notifications outside of a panel 

NOTE

Before proceeding, make sure `filament/notifications` is installed in your project. You can check by running:

 composer show filament/notifications

If it’s not installed, consult the [installation guide](../introduction/installation#installing-the-individual-components) and configure the **individual components** according to the instructions.

## [#](#introduction)Introduction

To render notifications in your app, make sure the `notifications` Livewire component is rendered in your layout:

 <div>
 @livewire('notifications')
 </div>

Now, when [sending a notification](../notifications) from a Livewire request, it will appear for the user.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/02-notifications.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
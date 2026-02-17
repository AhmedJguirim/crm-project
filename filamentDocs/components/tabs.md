# Source: https://filamentphp.com/docs/4.x/components/tabs

Components 

# Tabs Blade component 

## [#](#introduction)Introduction

The tabs component allows you to render a set of tabs, which can be used to toggle between multiple sections of content:

 <x-filament::tabs label="Content tabs">
 <x-filament::tabs.item>
 Tab 1
 </x-filament::tabs.item>
 
 <x-filament::tabs.item>
 Tab 2
 </x-filament::tabs.item>
 
 <x-filament::tabs.item>
 Tab 3
 </x-filament::tabs.item>
 </x-filament::tabs>

## [#](#triggering-the-active-state-of-the-tab)Triggering the active state of the tab

By default, tabs do not appear “active”. To make a tab appear active, you can use the `active` attribute:

 <x-filament::tabs>
 <x-filament::tabs.item active>
 Tab 1
 </x-filament::tabs.item>
 
 {{-- Other tabs --}}
 </x-filament::tabs>

You can also use the `active` attribute to make a tab appear active conditionally:

 <x-filament::tabs>
 <x-filament::tabs.item
 :active="$activeTab === 'tab1'"
 wire:click="$set('activeTab', 'tab1')"
 >
 Tab 1
 </x-filament::tabs.item>
 
 {{-- Other tabs --}}
 </x-filament::tabs>

Or you can use the `alpine-active` attribute to make a tab appear active conditionally using Alpine.js:

 <x-filament::tabs x-data="{ activeTab: 'tab1' }">
 <x-filament::tabs.item
 alpine-active="activeTab === 'tab1'"
 x-on:click="activeTab = 'tab1'"
 >
 Tab 1
 </x-filament::tabs.item>
 
 {{-- Other tabs --}}
 </x-filament::tabs>

## [#](#setting-a-tab-icon)Setting a tab icon

Tabs may have an [icon](../styling/icons), which you can set using the `icon` attribute:

 <x-filament::tabs>
 <x-filament::tabs.item icon="heroicon-m-bell">
 Notifications
 </x-filament::tabs.item>
 
 {{-- Other tabs --}}
 </x-filament::tabs>

### [#](#setting-the-tab-icon-position)Setting the tab icon position

The icon of the tab may be positioned before or after the label using the `icon-position` attribute:

 <x-filament::tabs>
 <x-filament::tabs.item
 icon="heroicon-m-bell"
 icon-position="after"
 >
 Notifications
 </x-filament::tabs.item>
 
 {{-- Other tabs --}}
 </x-filament::tabs>

## [#](#setting-a-tab-badge)Setting a tab badge

Tabs may have a [badge](badge), which you can set using the `badge` slot:

 <x-filament::tabs>
 <x-filament::tabs.item>
 Notifications
 
 <x-slot name="badge">
 5
 </x-slot>
 </x-filament::tabs.item>
 
 {{-- Other tabs --}}
 </x-filament::tabs>

## [#](#using-a-tab-as-an-anchor-link)Using a tab as an anchor link

By default, a tab’s underlying HTML tag is `<button>`. You can change it to be an `<a>` tag by using the `tag` attribute:

 <x-filament::tabs>
 <x-filament::tabs.item
 :href="route('notifications')"
 tag="a"
 >
 Notifications
 </x-filament::tabs.item>
 
 {{-- Other tabs --}}
 </x-filament::tabs>

## [#](#using-vertical-tabs)Using vertical tabs

You can render the tabs vertically by using the `vertical` attribute:

 <x-filament::tabs vertical>
 <x-filament::tabs.item>
 Tab 1
 </x-filament::tabs.item>
 
 <x-filament::tabs.item>
 Tab 2
 </x-filament::tabs.item>
 
 <x-filament::tabs.item>
 Tab 3
 </x-filament::tabs.item>
 </x-filament::tabs>

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-tabs.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
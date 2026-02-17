# Source: https://filamentphp.com/docs/4.x/components/section

Components 

# Section Blade component 

## [#](#introduction)Introduction

A section can be used to group content together, with an optional heading:

 <x-filament::section>
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

## [#](#adding-a-description-to-the-section)Adding a description to the section

You can add a description below the heading to the section by using the `description` slot:

 <x-filament::section>
 <x-slot name="heading">
 User details
 </x-slot>
 
 <x-slot name="description">
 This is all the information we hold about the user.
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

## [#](#adding-an-icon-to-the-section-header)Adding an icon to the section header

You can add an [icon](../styling/icons) to a section by using the `icon` attribute:

 <x-filament::section icon="heroicon-o-user">
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

### [#](#changing-the-color-of-the-section-icon)Changing the color of the section icon

By default, the color of the section icon is “gray”. You can change it to be `danger`, `info`, `primary`, `success` or `warning` by using the `icon-color` attribute:

 <x-filament::section
 icon="heroicon-o-user"
 icon-color="info"
 >
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

### [#](#changing-the-size-of-the-section-icon)Changing the size of the section icon

By default, the size of the section icon is “large”. You can change it to be “small” or “medium” by using the `icon-size` attribute:

 <x-filament::section
 icon="heroicon-m-user"
 icon-size="sm"
 >
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>
 
 <x-filament::section
 icon="heroicon-m-user"
 icon-size="md"
 >
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

## [#](#adding-content-to-the-end-of-the-header)Adding content to the end of the header

You may render additional content at the end of the header, next to the heading and description, using the `afterHeader` slot:

 <x-filament::section>
 <x-slot name="heading">
 User details
 </x-slot>
 
 <x-slot name="afterHeader">
 {{-- Input to select the user's ID --}}
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

## [#](#making-a-section-collapsible)Making a section collapsible

You can make the content of a section collapsible by using the `collapsible` attribute:

 <x-filament::section collapsible>
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

### [#](#making-a-section-collapsed-by-default)Making a section collapsed by default

You can make a section collapsed by default by using the `collapsed` attribute:

 <x-filament::section
 collapsible
 collapsed
 >
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

### [#](#persisting-collapsed-sections)Persisting collapsed sections

You can persist whether a section is collapsed in local storage using the `persist-collapsed` attribute, so it will remain collapsed when the user refreshes the page. You will also need a unique `id` attribute to identify the section from others, so that each section can persist its own collapse state:

 <x-filament::section
 collapsible
 collapsed
 persist-collapsed
 id="user-details"
 >
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

## [#](#adding-the-section-header-aside-the-content-instead-of-above-it)Adding the section header aside the content instead of above it

You can change the position of the section header to be aside the content instead of above it by using the `aside` attribute:

 <x-filament::section aside>
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

### [#](#positioning-the-content-before-the-header)Positioning the content before the header

You can change the position of the content to be before the header instead of after it by using the `content-before` attribute:

 <x-filament::section
 aside
 content-before
 >
 <x-slot name="heading">
 User details
 </x-slot>
 
 {{-- Content --}}
 </x-filament::section>

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-section.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
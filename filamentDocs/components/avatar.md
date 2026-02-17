# Source: https://filamentphp.com/docs/4.x/components/avatar

Components 

# Avatar Blade component 

## [#](#introduction)Introduction

The avatar component is used to render a circular or square image, often used to represent a user or entity as their “profile picture”:

 <x-filament::avatar
 src="https://filamentphp.com/dan.jpg"
 alt="Dan Harrin"
 />

## [#](#setting-the-rounding-of-an-avatar)Setting the rounding of an avatar

Avatars are fully rounded by default, but you may make them square by setting the `circular` attribute to `false`:

 <x-filament::avatar
 src="https://filamentphp.com/dan.jpg"
 alt="Dan Harrin"
 :circular="false"
 />

## [#](#setting-the-size-of-an-avatar)Setting the size of an avatar

By default, the avatar will be “medium” size. You can set the size to either `sm`, `md`, or `lg` using the `size` attribute:

 <x-filament::avatar
 src="https://filamentphp.com/dan.jpg"
 alt="Dan Harrin"
 size="lg"
 />

You can also pass your own custom size classes into the `size` attribute:

 <x-filament::avatar
 src="https://filamentphp.com/dan.jpg"
 alt="Dan Harrin"
 size="w-12 h-12"
 />

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-avatar.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
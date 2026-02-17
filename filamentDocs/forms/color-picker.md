# Source: https://filamentphp.com/docs/4.x/forms/color-picker

Forms 

# Color picker 

## [#](#introduction)Introduction

The color picker component allows you to pick a color in a range of formats.

By default, the component uses HEX format:

 use Filament\Forms\Components\ColorPicker;
 
 ColorPicker::make('color')

![Color picker](/docs/4.x/images/light/forms/fields/color-picker/simple.jpg) ![Color picker](/docs/4.x/images/dark/forms/fields/color-picker/simple.jpg)

## [#](#setting-the-color-format)Setting the color format

While HEX format is used by default, you can choose which color format to use:

 use Filament\Forms\Components\ColorPicker;
 
 ColorPicker::make('hsl_color')
 ->hsl()
 
 ColorPicker::make('rgb_color')
 ->rgb()
 
 ColorPicker::make('rgba_color')
 ->rgba()

## [#](#color-picker-validation)Color picker validation

You may use Laravel’s validation rules to validate the values of the color picker:

 use Filament\Forms\Components\ColorPicker;
 
 ColorPicker::make('hex_color')
 ->regex('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})\b$/')
 
 ColorPicker::make('hsl_color')
 ->hsl()
 ->regex('/^hsl\(\s*(\d+)\s*,\s*(\d*(?:\.\d+)?%)\s*,\s*(\d*(?:\.\d+)?%)\)$/')
 
 ColorPicker::make('rgb_color')
 ->rgb()
 ->regex('/^rgb\((\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})\)$/')
 
 ColorPicker::make('rgba_color')
 ->rgba()
 ->regex('/^rgba\((\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3}),\s*(\d*(?:\.\d+)?)\)$/')

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/forms/docs/17-color-picker.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
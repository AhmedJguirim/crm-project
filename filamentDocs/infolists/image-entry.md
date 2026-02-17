# Source: https://filamentphp.com/docs/4.x/infolists/image-entry

Infolists 

# Image entry 

## [#](#introduction)Introduction

Infolists can render images, based on the path in the state of the entry:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('header_image')

In this case, the `header_image` state could contain `posts/header-images/4281246003439.jpg`, which is relative to the root directory of the storage disk. The storage disk is defined in the [configuration file](../introduction/installation#publishing-configuration), `local` by default. You can also set the `FILESYSTEM_DISK` environment variable to change this.

Alternatively, the state could contain an absolute URL to an image, such as `https://example.com/images/header.jpg`.

![Image entry](/docs/4.x/images/light/infolists/entries/image/simple.jpg) ![Image entry](/docs/4.x/images/dark/infolists/entries/image/simple.jpg)

## [#](#managing-the-image-disk)Managing the image disk

The default storage disk is defined in the [configuration file](../introduction/installation#publishing-configuration), `local` by default. You can also set the `FILESYSTEM_DISK` environment variable to change this. If you want to deviate from the default disk, you may pass a custom disk name to the `disk()` method:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('header_image')
 ->disk('s3')

As well as allowing a static value, the `disk()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#public-images)Public images

By default, Filament will generate temporary URLs to images in the filesystem, unless the [disk](#managing-the-image-disk) is set to `public`. If your images are stored in a public disk, you can set the `visibility()` to `public`:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('header_image')
 ->visibility('public')

As well as allowing a static value, the `visibility()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#customizing-the-size)Customizing the size

You may customize the image size by passing a `imageWidth()` and `imageHeight()`, or both with `imageSize()`:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('header_image')
 ->imageWidth(200)
 
 ImageEntry::make('header_image')
 ->imageHeight(50)
 
 ImageEntry::make('author.avatar')
 ->imageSize(40)

As well as allowing a static values, the `imageWidth()`, `imageHeight()` and `imageSize()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
### [#](#square-images)Square images

You may display the image using a 1:1 aspect ratio:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('author.avatar')
 ->imageHeight(40)
 ->square()

![Square image entry](/docs/4.x/images/light/infolists/entries/image/square.jpg) ![Square image entry](/docs/4.x/images/dark/infolists/entries/image/square.jpg)

Optionally, you may pass a boolean value to control if the image should be square or not:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('author.avatar')
 ->imageHeight(40)
 ->square(FeatureFlag::active())

As well as allowing a static value, the `square()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#circular-images)Circular images

You may make the image fully rounded, which is useful for rendering avatars:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('author.avatar')
 ->imageHeight(40)
 ->circular()

![Circular image entry](/docs/4.x/images/light/infolists/entries/image/circular.jpg) ![Circular image entry](/docs/4.x/images/dark/infolists/entries/image/circular.jpg)

Optionally, you may pass a boolean value to control if the image should be circular or not:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('author.avatar')
 ->imageHeight(40)
 ->circular(FeatureFlag::active())

As well as allowing a static value, the `circular()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#adding-a-default-image-url)Adding a default image URL

You can display a placeholder image if one doesn’t exist yet, by passing a URL to the `defaultImageUrl()` method:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('header_image')
 ->defaultImageUrl(url('storage/posts/header-images/default.jpg'))

As well as allowing a static value, the `defaultImageUrl()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#stacking-images)Stacking images

You may display multiple images as a stack of overlapping images by using `stacked()`:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()

![Stacked image entry](/docs/4.x/images/light/infolists/entries/image/stacked.jpg) ![Stacked image entry](/docs/4.x/images/dark/infolists/entries/image/stacked.jpg)

Optionally, you may pass a boolean value to control if the images should be stacked or not:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked(FeatureFlag::active())

As well as allowing a static value, the `stacked()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
### [#](#customizing-the-stacked-ring-width)Customizing the stacked ring width

The default ring width is `3`, but you may customize it to be from `0` to `8`:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->ring(5)

As well as allowing a static value, the `ring()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
### [#](#customizing-the-stacked-overlap)Customizing the stacked overlap

The default overlap is `4`, but you may customize it to be from `0` to `8`:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->overlap(2)

As well as allowing a static value, the `overlap()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#setting-a-limit)Setting a limit

You may limit the maximum number of images you want to display by passing `limit()`:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->limit(3)

As well as allowing a static value, the `limit()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
![Limited image entry](/docs/4.x/images/light/infolists/entries/image/limited.jpg) ![Limited image entry](/docs/4.x/images/dark/infolists/entries/image/limited.jpg)

### [#](#showing-the-remaining-images-count)Showing the remaining images count

When you set a limit you may also display the count of remaining images by passing `limitedRemainingText()`.

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->limit(3)
 ->limitedRemainingText()

![Limited image entry with remaining text](/docs/4.x/images/light/infolists/entries/image/limited-remaining-text.jpg) ![Limited image entry with remaining text](/docs/4.x/images/dark/infolists/entries/image/limited-remaining-text.jpg)

Optionally, you may pass a boolean value to control if the remaining text should be displayed or not:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->limit(3)
 ->limitedRemainingText(FeatureFlag::active())

As well as allowing a static value, the `limitedRemainingText()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
#### [#](#customizing-the-limited-remaining-text-size)Customizing the limited remaining text size

By default, the size of the remaining text is `TextSize::Small`. You can customize this to be `TextSize::ExtraSmall`, `TextSize::Medium` or `TextSize::Large` using the `size` parameter:

 use Filament\Infolists\Components\ImageEntry;
 use Filament\Support\Enums\TextSize;
 
 ImageEntry::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->limit(3)
 ->limitedRemainingText(size: TextSize::Large)

As well as allowing a static value, the `limitedRemainingText()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#prevent-file-existence-checks)Prevent file existence checks

When the schema is loaded, it will automatically detect whether the images exist to prevent errors for missing files. This is all done on the backend. When using remote storage with many images, this can be time-consuming. You can use the `checkFileExistence(false)` method to disable this feature:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('attachment')
 ->checkFileExistence(false)

As well as allowing a static value, the `checkFileExistence()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#adding-extra-html-attributes-to-the-image)Adding extra HTML attributes to the image

You can pass extra HTML attributes to the `<img>` element via the `extraImgAttributes()` method. The attributes should be represented by an array, where the key is the attribute name and the value is the attribute value:

 use Filament\Infolists\Components\ImageEntry;
 
 ImageEntry::make('logo')
 ->extraImgAttributes([
 'alt' => 'Logo',
 'loading' => 'lazy',
 ])

As well as allowing a static value, the `extraImgAttributes()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
By default, calling `extraImgAttributes()` multiple times will overwrite the previous attributes. If you wish to merge the attributes instead, you can pass `merge: true` to the method.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/infolists/docs/04-image-entry.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
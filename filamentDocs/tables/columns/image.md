# Source: https://filamentphp.com/docs/4.x/tables/columns/image

Tables \- Columns 

# Image column 

## [#](#introduction)Introduction

Tables can render images, based on the path in the state of the column:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('avatar')

In this case, the `header_image` state could contain `posts/header-images/4281246003439.jpg`, which is relative to the root directory of the storage disk. The storage disk is defined in the [configuration file](../../introduction/installation#publishing-configuration), `local` by default. You can also set the `FILESYSTEM_DISK` environment variable to change this.

Alternatively, the state could contain an absolute URL to an image, such as `https://example.com/images/header.jpg`.

![Image column](/docs/4.x/images/light/tables/columns/image/simple.jpg) ![Image column](/docs/4.x/images/dark/tables/columns/image/simple.jpg)

## [#](#managing-the-image-disk)Managing the image disk

The default storage disk is defined in the [configuration file](../../introduction/installation#publishing-configuration), `local` by default. You can also set the `FILESYSTEM_DISK` environment variable to change this. If you want to deviate from the default disk, you may pass a custom disk name to the `disk()` method:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('header_image')
 ->disk('s3')

As well as allowing a static value, the `disk()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#public-images)Public images

By default, Filament will generate temporary URLs to images in the filesystem, unless the [disk](#managing-the-image-disk) is set to `public`. If your images are stored in a public disk, you can set the `visibility()` to `public`:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('header_image')
 ->visibility('public')

As well as allowing a static value, the `visibility()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#customizing-the-size)Customizing the size

You may customize the image size by passing a `imageWidth()` and `imageHeight()`, or both with `imageSize()`:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('header_image')
 ->imageWidth(200)
 
 ImageColumn::make('header_image')
 ->imageHeight(50)
 
 ImageColumn::make('avatar')
 ->imageSize(40)

As well as allowing a static values, the `imageWidth()`, `imageHeight()` and `imageSize()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#square-images)Square images

You may display the image using a 1:1 aspect ratio:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('avatar')
 ->imageHeight(40)
 ->square()

![Square image column](/docs/4.x/images/light/tables/columns/image/square.jpg) ![Square image column](/docs/4.x/images/dark/tables/columns/image/square.jpg)

Optionally, you may pass a boolean value to control if the image should be square or not:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('avatar')
 ->imageHeight(40)
 ->square(FeatureFlag::active())

As well as allowing a static value, the `square()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#circular-images)Circular images

You may make the image fully rounded, which is useful for rendering avatars:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('avatar')
 ->imageHeight(40)
 ->circular()

![Circular image column](/docs/4.x/images/light/tables/columns/image/circular.jpg) ![Circular image column](/docs/4.x/images/dark/tables/columns/image/circular.jpg)

Optionally, you may pass a boolean value to control if the image should be circular or not:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('avatar')
 ->imageHeight(40)
 ->circular(FeatureFlag::active())

As well as allowing a static value, the `circular()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#adding-a-default-image-url)Adding a default image URL

You can display a placeholder image if one doesn’t exist yet, by passing a URL to the `defaultImageUrl()` method:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('header_image')
 ->defaultImageUrl(url('storage/posts/header-images/default.jpg'))

As well as allowing a static value, the `defaultImageUrl()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#stacking-images)Stacking images

You may display multiple images as a stack of overlapping images by using `stacked()`:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()

![Stacked image column](/docs/4.x/images/light/tables/columns/image/stacked.jpg) ![Stacked image column](/docs/4.x/images/dark/tables/columns/image/stacked.jpg)

Optionally, you may pass a boolean value to control if the images should be stacked or not:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked(FeatureFlag::active())

As well as allowing a static value, the `stacked()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#customizing-the-stacked-ring-width)Customizing the stacked ring width

The default ring width is `3`, but you may customize it to be from `0` to `8`:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->ring(5)

As well as allowing a static value, the `ring()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#customizing-the-stacked-overlap)Customizing the stacked overlap

The default overlap is `4`, but you may customize it to be from `0` to `8`:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->overlap(2)

As well as allowing a static value, the `overlap()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#setting-a-limit)Setting a limit

You may limit the maximum number of images you want to display by passing `limit()`:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->limit(3)

As well as allowing a static value, the `limit()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Limited image column](/docs/4.x/images/light/tables/columns/image/limited.jpg) ![Limited image column](/docs/4.x/images/dark/tables/columns/image/limited.jpg)

### [#](#showing-the-remaining-images-count)Showing the remaining images count

When you set a limit you may also display the count of remaining images by passing `limitedRemainingText()`.

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->limit(3)
 ->limitedRemainingText()

![Limited image column with remaining text](/docs/4.x/images/light/tables/columns/image/limited-remaining-text.jpg) ![Limited image column with remaining text](/docs/4.x/images/dark/tables/columns/image/limited-remaining-text.jpg)

Optionally, you may pass a boolean value to control if the remaining text should be displayed or not:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->limit(3)
 ->limitedRemainingText(FeatureFlag::active())

As well as allowing a static value, the `limitedRemainingText()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
#### [#](#customizing-the-limited-remaining-text-size)Customizing the limited remaining text size

By default, the size of the remaining text is `TextSize::Small`. You can customize this to be `TextSize::ExtraSmall`, `TextSize::Medium` or `TextSize::Large` using the `size` parameter:

 use Filament\Tables\Columns\ImageColumn;
 use Filament\Support\Enums\TextSize;
 
 ImageColumn::make('colleagues.avatar')
 ->imageHeight(40)
 ->circular()
 ->stacked()
 ->limit(3)
 ->limitedRemainingText(size: TextSize::Large)

As well as allowing a static value, the `limitedRemainingText()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#prevent-file-existence-checks)Prevent file existence checks

When the schema is loaded, it will automatically detect whether the images exist to prevent errors for missing files. This is all done on the backend. When using remote storage with many images, this can be time-consuming. You can use the `checkFileExistence(false)` method to disable this feature:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('attachment')
 ->checkFileExistence(false)

As well as allowing a static value, the `checkFileExistence()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#wrapping-multiple-images)Wrapping multiple images

Images can be set to wrap if they can’t fit on one line, by setting `wrap()`:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('colleagues.avatar')
 ->circular()
 ->stacked()
 ->wrap()

TIP

The “width” for wrapping is affected by the column label, so you may need to use a shorter or hidden label to wrap more tightly.

## [#](#adding-extra-html-attributes-to-the-image)Adding extra HTML attributes to the image

You can pass extra HTML attributes to the `<img>` element via the `extraImgAttributes()` method. The attributes should be represented by an array, where the key is the attribute name and the value is the attribute value:

 use Filament\Tables\Columns\ImageColumn;
 
 ImageColumn::make('logo')
 ->extraImgAttributes([
 'alt' => 'Logo',
 'loading' => 'lazy',
 ])

As well as allowing a static value, the `extraImgAttributes()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
By default, calling `extraImgAttributes()` multiple times will overwrite the previous attributes. If you wish to merge the attributes instead, you can pass `merge: true` to the method.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/04-image.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
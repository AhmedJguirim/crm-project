# Source: https://filamentphp.com/docs/4.x/schemas/primes

Schemas 

# Prime components 

## [#](#introduction)Introduction

Within Filament schemas, prime components are the most basic building blocks that can be used to insert arbitrary content into a schema, such as text and images. As the name suggests, prime components are not divisible and cannot be made simpler. Filament provides a set of built-in prime components:

 * [Text](#text-component)
 * [Icon](#icon-component)
 * [Image](#image-component)
 * [Unordered list](#unordered-list-component)

You may also [create your own custom components](custom-components) to add your own arbitrary content to a schema.

![An example of using prime components to set up two-factor authentication.](/docs/4.x/images/light/primes/overview/example.jpg) ![An example of using prime components to set up two-factor authentication.](/docs/4.x/images/dark/primes/overview/example.jpg)

In this example, prime components are being used to display instructions to the user, a QR code that the user can scan, and list of recovery codes that the user can save:

 use Filament\Actions\Action;
 use Filament\Schemas\Components\Image;
 use Filament\Schemas\Components\Section;
 use Filament\Schemas\Components\Text;
 use Filament\Schemas\Components\UnorderedList;
 use Filament\Support\Enums\FontWeight;
 use Filament\Support\Enums\TextSize;
 
 $schema
 ->components([
 Text::make('Scan this QR code with your authenticator app:')
 ->color('neutral'),
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )
 ->imageHeight('12rem')
 ->alignCenter(),
 Section::make()
 ->schema([
 Text::make('Please save the following recovery codes in a safe place. They will only be shown once, but you\'ll need them if you lose access to your authenticator app:')
 ->weight(FontWeight::Bold)
 ->color('neutral'),
 UnorderedList::make(fn (): array => array_map(
 fn (string $recoveryCode): Text => Text::make($recoveryCode)
 ->copyable()
 ->fontFamily(FontFamily::Mono)
 ->size(TextSize::ExtraSmall)
 ->color('neutral'),
 ['tYRnCqNLUx-3QOLNKyDiV', 'cKok2eImKc-oWHHH4VhNe', 'C0ZstEcSSB-nrbyk2pv8z', '49EXLRQ8MI-FpWywpSDHE', 'TXjHnvkUrr-KuiVJENPmJ', 'BB574ookll-uI20yxP6oa', 'BbgScF2egu-VOfHrMtsCl', 'cO0dJYqmee-S9ubJHpRFR'],
 ))
 ->size(TextSize::ExtraSmall),
 ])
 ->compact()
 ->secondary(),
 ])

Although text can be rendered in a schema using an [infolist text entry](../infolists/text-entry), entries are intended to render a label-value detail about an entity (like an Eloquent model), and not to render arbitrary text. Prime components are more suitable for this purpose. Infolists can be considered more similar to [description lists](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/dl) in HTML.

Prime component classes can be found in the `Filament\Schemas\Components` namespace. They reside within the schema array of components.

## [#](#text-component)Text component

Text can be inserted into a schema using the `Text` component. Text content is passed to the `make()` method:

 use Filament\Schemas\Components\Text;
 
 Text::make('Modifying these permissions may give users access to sensitive information.')

![Text](/docs/4.x/images/light/primes/text/simple.jpg) ![Text](/docs/4.x/images/dark/primes/text/simple.jpg)

To render raw HTML content, you can pass an `HtmlString` object to the `make()` method:

 use Filament\Schemas\Components\Text;
 use Illuminate\Support\HtmlString;
 
 Text::make(new HtmlString('<strong>Warning:</strong> Modifying these permissions may give users access to sensitive information.'))

NOTE

Be aware that you will need to ensure that the HTML is safe to render, otherwise your application will be vulnerable to XSS attacks.

![Text with HTML](/docs/4.x/images/light/primes/text/html.jpg) ![Text with HTML](/docs/4.x/images/dark/primes/text/html.jpg)

To render Markdown, you can use Laravel’s `str()` helper to convert Markdown to HTML, and then transform it into an `HtmlString` object:

 use Filament\Schemas\Components\Text;
 
 Text::make(
 str('**Warning:** Modifying these permissions may give users access to sensitive information.')
 ->inlineMarkdown()
 ->toHtmlString(),
 )

As well as allowing a static value, the `make()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
### [#](#customizing-the-text-color)Customizing the text color

You may set a [color](../styling/colors) for the text:

 use Filament\Schemas\Components\Text;
 
 Text::make('Information')
 ->color('info')

As well as allowing a static value, the `color()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Text in the info color](/docs/4.x/images/light/primes/text/color.jpg) ![Text in the info color](/docs/4.x/images/dark/primes/text/color.jpg)

### [#](#using-a-neutral-color)Using a neutral color

By default, the text color is set to `gray`, which is typically fairly dim against its background. You can darken it using the `color('neutral')` method:

 use Filament\Schemas\Components\Text;
 
 Text::make('Modifying these permissions may give users access to sensitive information.')
 
 Text::make('Modifying these permissions may give users access to sensitive information.')
 ->color('neutral')

![Text in the neutral color](/docs/4.x/images/light/primes/text/neutral.jpg) ![Text in the neutral color](/docs/4.x/images/dark/primes/text/neutral.jpg)

### [#](#displaying-as-a-badge)Displaying as a “badge”

By default, text is quite plain and has no background color. You can make it appear as a “badge” instead using the `badge()` method. A great use case for this is with statuses, where may want to display a badge with a [color](#customizing-the-text-color) that matches the status:

 use Filament\Schemas\Components\Text;
 
 Text::make('Warning')
 ->color('warning')
 ->badge()

![Text as badge](/docs/4.x/images/light/primes/text/badge.jpg) ![Text as badge](/docs/4.x/images/dark/primes/text/badge.jpg)

Optionally, you may pass a boolean value to control if the text should be in a badge or not:

 use Filament\Schemas\Components\Text;
 
 Text::make('Warning')
 ->color('warning')
 ->badge(FeatureFlag::active())

As well as allowing a static value, the `badge()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
#### [#](#adding-an-icon-to-the-badge)Adding an icon to the badge

You may add other things to the badge, like an [icon](../styling/icons):

 use Filament\Schemas\Components\Text;
 use Filament\Support\Icons\Heroicon;
 
 Text::make('Warning')
 ->color('warning')
 ->badge()
 ->icon(Heroicon::ExclamationTriangle)

![Text as badge with an icon](/docs/4.x/images/light/primes/text/badge-icon.jpg) ![Text as badge with an icon](/docs/4.x/images/dark/primes/text/badge-icon.jpg)

### [#](#customizing-the-text-size)Customizing the text size

Text has a small font size by default, but you may change this to `TextSize::ExtraSmall`, `TextSize::Medium`, or `TextSize::Large`.

For instance, you may make the text larger using `size(TextSize::Large)`:

 use Filament\Schemas\Components\Text;
 use Filament\Support\Enums\TextSize;
 
 Text::make('Modifying these permissions may give users access to sensitive information.')
 ->size(TextSize::Large)

![Text entry in a large font size](/docs/4.x/images/light/primes/text/large.jpg) ![Text entry in a large font size](/docs/4.x/images/dark/primes/text/large.jpg)

### [#](#customizing-the-font-weight)Customizing the font weight

Text entries have regular font weight by default, but you may change this to any of the following options: `FontWeight::Thin`, `FontWeight::ExtraLight`, `FontWeight::Light`, `FontWeight::Medium`, `FontWeight::SemiBold`, `FontWeight::Bold`, `FontWeight::ExtraBold` or `FontWeight::Black`.

For instance, you may make the font bold using `weight(FontWeight::Bold)`:

 use Filament\Schemas\Components\Text;
 use Filament\Support\Enums\FontWeight;
 
 Text::make('Modifying these permissions may give users access to sensitive information.')
 ->weight(FontWeight::Bold)

As well as allowing a static value, the `weight()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Text entry in a bold font](/docs/4.x/images/light/primes/text/bold.jpg) ![Text entry in a bold font](/docs/4.x/images/dark/primes/text/bold.jpg)

### [#](#customizing-the-font-family)Customizing the font family

You can change the text font family to any of the following options: `FontFamily::Sans`, `FontFamily::Serif` or `FontFamily::Mono`.

For instance, you may make the font monospaced using `fontFamily(FontFamily::Mono)`:

 use Filament\Support\Enums\FontFamily;
 use Filament\Schemas\Components\Text;
 
 Text::make('28o.-AK%D~xh*.:[4"3)zPiC')
 ->fontFamily(FontFamily::Mono)

As well as allowing a static value, the `fontFamily()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Text entry in a monospaced font](/docs/4.x/images/light/primes/text/mono.jpg) ![Text entry in a monospaced font](/docs/4.x/images/dark/primes/text/mono.jpg)

### [#](#adding-a-tooltip-to-the-text)Adding a tooltip to the text

You may add a tooltip to the text using the `tooltip()` method:

 use Filament\Schemas\Components\Text;
 
 Text::make('28o.-AK%D~xh*.:[4"3)zPiC')
 ->tooltip('Your secret recovery code')

As well as allowing a static value, the `tooltip()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Text with a tooltip](/docs/4.x/images/light/primes/text/tooltip.jpg) ![Text with a tooltip](/docs/4.x/images/dark/primes/text/tooltip.jpg)

### [#](#using-javascript-to-determine-the-content-of-the-text)Using JavaScript to determine the content of the text

You can use JavaScript to determine the content of the text. This is useful if you want to display a different message depending on the state of a [form field](../forms/overview), without making a request to the server to re-render the schema. To allow this, you can use the `js()` method:

 use Filament\Schemas\Components\Text;
 
 Text::make(<<<'JS'
 $get('name') ? `Hello, ${$get('name')}` : 'Please enter your name.'
 JS)
 ->js()

The [`$state`](../forms/overview#injecting-the-current-state-of-the-field) and [`$get()`](../forms/overview#injecting-the-state-of-another-field) utilities are available in the JavaScript context, so you can use them to get the state of fields in the schema.

## [#](#icon-component)Icon component

Icons can be inserted into a schema using the `Icon` component. [Icons](../styling/icons) are passed to the `make()` method:

 use Filament\Schemas\Components\Icon;
 use Filament\Support\Icons\Heroicon;
 
 Icon::make(Heroicon::Star)

As well as allowing a static value, the `make()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Icon](/docs/4.x/images/light/primes/icon/simple.jpg) ![Icon](/docs/4.x/images/dark/primes/icon/simple.jpg)

### [#](#customizing-the-icon-color)Customizing the icon color

You may set a [color](../styling/colors) for the icon:

 use Filament\Schemas\Components\Icon;
 use Filament\Support\Icons\Heroicon;
 
 Icon::make(Heroicon::ExclamationCircle)
 ->color('danger')

As well as allowing a static value, the `color()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Icon in the danger color](/docs/4.x/images/light/primes/icon/color.jpg) ![Icon in the danger color](/docs/4.x/images/dark/primes/icon/color.jpg)

### [#](#adding-a-tooltip-to-the-icon)Adding a tooltip to the icon

You may add a tooltip to the icon using the `tooltip()` method:

 use Filament\Schemas\Components\Icon;
 use Filament\Support\Icons\Heroicon;
 
 Icon::make(Heroicon::ExclamationTriangle)
 ->tooltip('Warning')

As well as allowing a static value, the `tooltip()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Icon with a tooltip](/docs/4.x/images/light/primes/icon/tooltip.jpg) ![Icon with a tooltip](/docs/4.x/images/dark/primes/icon/tooltip.jpg)

## [#](#image-component)Image component

Images can be inserted into a schema using the `Image` component. The image URL and alt text are passed to the `make()` method:

 use Filament\Schemas\Components\Image;
 
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )

As well as allowing a static values, the arguments of the `make()` method also accept functions to dynamically calculate them. You can inject various utilities into the functions as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Image](/docs/4.x/images/light/primes/image/simple.jpg) ![Image](/docs/4.x/images/dark/primes/image/simple.jpg)

### [#](#customizing-the-image-size)Customizing the image size

You may customize the image size by passing a `imageWidth()` and `imageHeight()`, or both with `imageSize()`:

 use Filament\Schemas\Components\Image;
 
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )
 ->imageWidth('12rem')
 
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )
 ->imageHeight('12rem')
 
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )
 ->imageSize('12rem')

As well as allowing a static values, the `imageWidth()`, `imageHeight()` and `imageSize()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Image with a custom size](/docs/4.x/images/light/primes/image/size.jpg) ![Image with a custom size](/docs/4.x/images/dark/primes/image/size.jpg)

### [#](#aligning-the-image)Aligning the image

You may align the image to the start (left in left-to-right interfaces, right in right-to-left interfaces), center, or end (right in left-to-right interfaces, left in right-to-left interfaces) using the `alignStart()`, `alignCenter()` or `alignEnd()` methods:

 use Filament\Schemas\Components\Image;
 
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )
 ->alignStart() // This is the default alignment.
 
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )
 ->alignCenter()
 
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )
 ->alignEnd()

Alternatively, you may pass an `Alignment` enum to the `alignment()` method:

 use Filament\Schemas\Components\Image;
 use Filament\Support\Enums\Alignment;
 
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )
 ->alignment(Alignment::Center)

As well as allowing a static value, the `alignment()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Image with a custom alignment](/docs/4.x/images/light/primes/image/alignment.jpg) ![Image with a custom alignment](/docs/4.x/images/dark/primes/image/alignment.jpg)

### [#](#adding-a-tooltip-to-the-image)Adding a tooltip to the image

You may add a tooltip to the image using the `tooltip()` method:

 use Filament\Schemas\Components\Image;
 
 Image::make(
 url: asset('images/qr.jpg'),
 alt: 'QR code to scan with an authenticator app',
 )
 ->tooltip('Scan this QR code with your authenticator app')
 ->alignCenter()

As well as allowing a static value, the `tooltip()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Image with a tooltip](/docs/4.x/images/light/primes/image/tooltip.jpg) ![Image with a tooltip](/docs/4.x/images/dark/primes/image/tooltip.jpg)

## [#](#unordered-list-component)Unordered list component

Unordered lists can be inserted into a schema using the `UnorderedList` component. The list items, comprising plain text or [text components](#text-component), are passed to the `make()` method:

 use Filament\Schemas\Components\UnorderedList;
 
 UnorderedList::make([
 'Tables',
 'Schemas',
 'Actions',
 'Notifications',
 ])

As well as allowing a static value, the `make()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Unordered list](/docs/4.x/images/light/primes/unordered-list/simple.jpg) ![Unordered list](/docs/4.x/images/dark/primes/unordered-list/simple.jpg)

Text components can be used as list items, which allows you to customize the formatting of each item:

 use Filament\Schemas\Components\Text;
 use Filament\Schemas\Components\UnorderedList;
 use Filament\Support\Enums\FontFamily;
 
 UnorderedList::make([
 Text::make('Tables')->fontFamily(FontFamily::Mono),
 Text::make('Schemas')->fontFamily(FontFamily::Mono),
 Text::make('Actions')->fontFamily(FontFamily::Mono),
 Text::make('Notifications')->fontFamily(FontFamily::Mono),
 ])

### [#](#customizing-the-bullet-size)Customizing the bullet size

If you are modifying the text size of the list content, you will probably want to adjust the size of the bullets to match. To do this, you can use the `size()` method. Bullets have small font size by default, but you may change this to `TextSize::ExtraSmall`, `TextSize::Medium`, or `TextSize::Large`.

For instance, you may make the bullets larger using `size(TextSize::Large)`:

 use Filament\Schemas\Components\Text;
 use Filament\Schemas\Components\UnorderedList;
 
 UnorderedList::make([
 Text::make('Tables')->size(TextSize::Large),
 Text::make('Schemas')->size(TextSize::Large),
 Text::make('Actions')->size(TextSize::Large),
 Text::make('Notifications')->size(TextSize::Large),
 ])
 ->size(TextSize::Large)

As well as allowing a static value, the `size()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Unordered list with large bullets](/docs/4.x/images/light/primes/unordered-list/large.jpg) ![Unordered list with large bullets](/docs/4.x/images/dark/primes/unordered-list/large.jpg)

## [#](#adding-extra-html-attributes-to-a-prime-component)Adding extra HTML attributes to a prime component

You can pass extra HTML attributes to the component via the `extraAttributes()` method, which will be merged onto its outer HTML element. The attributes should be represented by an array, where the key is the attribute name and the value is the attribute value:

 use Filament\Schemas\Components\Text;
 
 Text::make('Modifying these permissions may give users access to sensitive information.')
 ->extraAttributes(['class' => 'custom-text-style'])

As well as allowing a static value, the `extraAttributes()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
By default, calling `extraAttributes()` multiple times will overwrite the previous attributes. If you wish to merge the attributes instead, you can pass `merge: true` to the method.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/schemas/docs/07-primes.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
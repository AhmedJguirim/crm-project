# Source: https://filamentphp.com/docs/4.x/forms/code-editor

Forms 

# Code editor 

## [#](#introduction)Introduction

The code editor component allows you to write code in a textarea with line numbers. By default, no syntax highlighting is applied.

 use Filament\Forms\Components\CodeEditor;
 
 CodeEditor::make('code')

![Code editor](/docs/4.x/images/light/forms/fields/code-editor/simple.jpg) ![Code editor](/docs/4.x/images/dark/forms/fields/code-editor/simple.jpg)

## [#](#using-language-syntax-highlighting)Using language syntax highlighting

You may change the language syntax highlighting of the code editor using the `language()` method. The editor supports the following languages:

 * C++
 * CSS
 * Go
 * HTML
 * Java
 * JavaScript
 * JSON
 * Markdown
 * PHP
 * Python
 * SQL
 * XML
 * YAML

You can open the `Filament\Forms\Components\CodeEditor\Enums\Language` enum class to see this list. To switch to using JavaScript syntax highlighting, you can use the `Language::JavaScript` enum value:

 use Filament\Forms\Components\CodeEditor;
 use Filament\Forms\Components\CodeEditor\Enums\Language;
 
 CodeEditor::make('code')
 ->language(Language::JavaScript)

As well as allowing a static value, the `language()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Code editor with syntax highlighting](/docs/4.x/images/light/forms/fields/code-editor/language.jpg) ![Code editor with syntax highlighting](/docs/4.x/images/dark/forms/fields/code-editor/language.jpg)

## [#](#allowing-lines-to-wrap)Allowing lines to wrap

By default, long lines in the code editor will create a horizontal scrollbar. If you would like to allow long lines to wrap instead, you may use the `wrap()` method:

 use Filament\Forms\Components\CodeEditor;
 
 CodeEditor::make('code')
 ->wrap()

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/forms/docs/20-code-editor.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
# Source: https://filamentphp.com/docs/4.x/testing/testing-notifications

Testing 

# Testing notifications 

## [#](#testing-session-notifications)Testing session notifications

To check if a notification was sent using the session, use the `assertNotified()` helper:

 use function Pest\Livewire\livewire;
 
 it('sends a notification', function () {
 livewire(CreatePost::class)
 ->assertNotified();
 });

 use Filament\Notifications\Notification;
 
 it('sends a notification', function () {
 Notification::assertNotified();
 });

 use function Filament\Notifications\Testing\assertNotified;
 
 it('sends a notification', function () {
 assertNotified();
 });

You may optionally pass a notification title to test for:

 use Filament\Notifications\Notification;
 use function Pest\Livewire\livewire;
 
 it('sends a notification', function () {
 livewire(CreatePost::class)
 ->assertNotified('Unable to create post');
 });

Or test if the exact notification was sent:

 use Filament\Notifications\Notification;
 use function Pest\Livewire\livewire;
 
 it('sends a notification', function () {
 livewire(CreatePost::class)
 ->assertNotified(
 Notification::make()
 ->danger()
 ->title('Unable to create post')
 ->body('Something went wrong.'),
 );
 });

Conversely, you can assert that a notification was not sent:

 use Filament\Notifications\Notification;
 use function Pest\Livewire\livewire;
 
 it('does not send a notification', function () {
 livewire(CreatePost::class)
 ->assertNotNotified()
 // or
 ->assertNotNotified('Unable to create post')
 // or
 ->assertNotNotified(
 Notification::make()
 ->danger()
 ->title('Unable to create post')
 ->body('Something went wrong.'),
 );

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/10-testing/06-testing-notifications.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
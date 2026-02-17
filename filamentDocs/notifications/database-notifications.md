# Source: https://filamentphp.com/docs/4.x/notifications/database-notifications

Notifications 

# Database notifications 

![Database notifications](/docs/4.x/images/light/notifications/database.jpg) ![Database notifications](/docs/4.x/images/dark/notifications/database.jpg)

## [#](#setting-up-the-notifications-database-table)Setting up the notifications database table

Before we start, make sure that the [Laravel notifications table](https://laravel.com/docs/notifications#database-prerequisites) is added to your database:

 php artisan make:notifications-table

> If you’re using PostgreSQL, make sure that the `data` column in the migration is using `json()`: `$table->json('data')`.

> If you’re using UUIDs for your `User` model, make sure that your `notifiable` column is using `uuidMorphs()`: `$table->uuidMorphs('notifiable')`.

## [#](#enabling-database-notifications-in-a-panel)Enabling database notifications in a panel

If you’d like to receive database notifications in a panel, you can enable them in the [configuration](../panel-configuration):

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->databaseNotifications();
 }

## [#](#sending-database-notifications)Sending database notifications

There are several ways to send database notifications, depending on which one suits you best.

You may use our fluent API:

 use Filament\Notifications\Notification;
 
 $recipient = auth()->user();
 
 Notification::make()
 ->title('Saved successfully')
 ->sendToDatabase($recipient);

Or, use the `notify()` method:

 use Filament\Notifications\Notification;
 
 $recipient = auth()->user();
 
 $recipient->notify(
 Notification::make()
 ->title('Saved successfully')
 ->toDatabase(),
 );

> Laravel sends database notifications using the queue. Ensure your queue is running in order to receive the notifications.

Alternatively, use a traditional [Laravel notification class](https://laravel.com/docs/notifications#generating-notifications) by returning the notification from the `toDatabase()` method:

 use App\Models\User;
 use Filament\Notifications\Notification;
 
 public function toDatabase(User $notifiable): array
 {
 return Notification::make()
 ->title('Saved successfully')
 ->getDatabaseMessage();
 }

## [#](#moving-the-database-notifications-trigger-to-the-panel-sidebar)Moving the database notifications trigger to the panel sidebar

By default, the database notifications trigger is positioned in the topbar. If the topbar is disabled, it is added to the sidebar.

You can choose to always move it to the sidebar by passing a `position` argument to the `databaseNotifications()` method in the [configuration](../panel-configuration):

 use Filament\Enums\DatabaseNotificationsPosition;
 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->databaseNotifications(position: DatabaseNotificationsPosition::Sidebar);
 }

## [#](#receiving-database-notifications)Receiving database notifications

Without any setup, new database notifications will only be received when the page is first loaded.

### [#](#polling-for-new-database-notifications)Polling for new database notifications

Polling is the practice of periodically making a request to the server to check for new notifications. This is a good approach as the setup is simple, but some may say that it is not a scalable solution as it increases server load.

By default, Livewire polls for new notifications every 30 seconds:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->databaseNotifications()
 ->databaseNotificationsPolling('30s');
 }

You may completely disable polling if you wish:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->databaseNotifications()
 ->databaseNotificationsPolling(null);
 }

### [#](#using-echo-to-receive-new-database-notifications-with-websockets)Using Echo to receive new database notifications with websockets

Websockets are a more efficient way to receive new notifications in real-time. To set up websockets, you must [configure it](broadcast-notifications#setting-up-websockets-in-a-panel) in the panel first.

Once websockets are set up, you can automatically dispatch a `DatabaseNotificationsSent` event by setting the `isEventDispatched` parameter to `true` when sending the notification. This will trigger the immediate fetching of new notifications for the user:

 use Filament\Notifications\Notification;
 
 $recipient = auth()->user();
 
 Notification::make()
 ->title('Saved successfully')
 ->sendToDatabase($recipient, isEventDispatched: true);

## [#](#marking-database-notifications-as-read)Marking database notifications as read

There is a button at the top of the modal to mark all notifications as read at once. You may also add [Actions](overview#adding-actions-to-notifications) to notifications, which you can use to mark individual notifications as read. To do this, use the `markAsRead()` method on the action:

 use Filament\Actions\Action;
 use Filament\Notifications\Notification;
 
 Notification::make()
 ->title('Saved successfully')
 ->success()
 ->body('Changes to the post have been saved.')
 ->actions([
 Action::make('view')
 ->button()
 ->markAsRead(),
 ])
 ->send();

Alternatively, you may use the `markAsUnread()` method to mark a notification as unread:

 use Filament\Actions\Action;
 use Filament\Notifications\Notification;
 
 Notification::make()
 ->title('Saved successfully')
 ->success()
 ->body('Changes to the post have been saved.')
 ->actions([
 Action::make('markAsUnread')
 ->button()
 ->markAsUnread(),
 ])
 ->send();

## [#](#opening-the-database-notifications-modal)Opening the database notifications modal

You can open the database notifications modal from anywhere by dispatching an `open-modal` browser event:

 <button
 x-data="{}"
 x-on:click="$dispatch('open-modal', { id: 'database-notifications' })"
 type="button"
 >
 Notifications
 </button>

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/notifications/docs/02-database-notifications.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)
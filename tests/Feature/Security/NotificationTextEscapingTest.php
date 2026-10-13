<?php

use App\Filament\Resources\CompanyTypes\Pages\ManageCompanyTypes;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\SyncSegmentMembership;
use App\Models\CompanyType;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Segment;
use App\Models\User;
use App\Support\NotificationText;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('the escaped text no longer renders a link', function () {
    $html = Str::sanitizeHtml(NotificationText::plain('<a href="https://evil.example" style="position:fixed;inset:0">x</a>'));

    expect($html)->not->toContain('<a ')
        ->not->toContain('style="position')
        ->toContain('&lt;a href');
});

test('a contact import error does not render the cell as a link', function () {
    Storage::fake('local');
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $user->personalOrganization();
    $cell = '<a href=""https://evil.example"" style=""position:fixed;inset:0"">Session expired, log in again</a>';
    Storage::disk('local')->put('contact-imports/xss.csv', "name,email\nEve,\"{$cell}\"\n");

    ProcessContactImportJob::dispatchSync('contact-imports/xss.csv', $org->id, $user->id);

    $notification = DatabaseNotification::where('notifiable_id', $user->id)->latest()->firstOrFail();
    $html = Notification::fromDatabase($notification)->toEmbeddedHtml();

    expect($notification->data['title'])->toBe('Import complete with errors')
        ->and($notification->data['body'])->toContain('Invalid email: &lt;a href="https://evil.example"')
        ->and($html)->not->toContain('<a href="https://evil.example"')
        ->not->toContain('style="position:fixed');
});

test('a segment name does not render as a link', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $user->personalOrganization();
    $segment = Segment::factory()->for($org)->published()->create(['name' => '<a href="https://evil.example" style="position:fixed;inset:0">VIP</a>']);

    SyncSegmentMembership::dispatchSync($segment->id, $user->id);

    $notification = DatabaseNotification::where('notifiable_id', $user->id)->latest()->firstOrFail();

    expect($notification->data['body'])->toBe("'&lt;a href=\"https://evil.example\" style=\"position:fixed;inset:0\"&gt;VIP&lt;/a&gt;' now has 0 contacts.")
        ->and(Notification::fromDatabase($notification)->toEmbeddedHtml())->not->toContain('<a href="https://evil.example"');
});

test('the organization name in the welcome notification is escaped', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'member@example.com']);
    $invite = OrganizationInvite::factory()->create([
        'email' => 'member@example.com',
        'organization_id' => Organization::factory()->create(['name' => '<a href="https://evil.example">Acme</a>'])->id,
    ]);

    $this->actingAs($user)->get(route('invite.accept', $invite->token));

    $titles = collect(session('filament.notifications'))->pluck('title')->all();

    expect($titles)->toContain('Welcome to &lt;a href="https://evil.example"&gt;Acme&lt;/a&gt;!');
});

test('a restored company type name is escaped in the refusal', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();
    $this->actingAs($owner);
    Filament::setTenant($org);
    $trashed = CompanyType::factory()->create(['organization_id' => $org->id, 'name' => '<b>Startup</b>']);
    $trashed->delete();
    CompanyType::factory()->create(['organization_id' => $org->id, 'name' => '<b>startup</b>']);

    Livewire::test(ManageCompanyTypes::class)
        ->filterTable('trashed', true)
        ->callAction(TestAction::make(RestoreAction::class)->table($trashed))
        ->assertNotified('A company type named "&lt;b&gt;Startup&lt;/b&gt;" already exists. Rename one of them first.');

    expect($trashed->fresh()->trashed())->toBeTrue();
});

<?php

use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Segment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->field = CustomField::factory()->multiselect()->create(['organization_id' => $this->org->id, 'name' => 'Tech Stack', 'unique' => false, 'order' => 1]);
    $this->other = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Notes', 'type' => 'text', 'unique' => false, 'order' => 2]);

    $this->broken = Contact::factory()->for($this->org)->create(['custom_field_values' => [
        $this->field->key => [0 => 'laravel', 2 => 'react'],
        $this->other->key => 'Keep me',
    ]]);
    $this->trashed = Contact::factory()->for($this->org)->create(['custom_field_values' => [$this->field->key => [0 => 'laravel', 2 => 'react']]]);
    $this->trashed->delete();
    $this->healthy = Contact::factory()->for($this->org)->create(['custom_field_values' => [$this->field->key => ['vue']]]);
});

function storedCustomFieldValues(Contact $contact): string
{
    return DB::table('contacts')->where('id', $contact->id)->value('custom_field_values');
}

it('lists the broken contacts on a dry run and changes nothing', function () {
    $before = [storedCustomFieldValues($this->broken), storedCustomFieldValues($this->trashed), storedCustomFieldValues($this->healthy)];

    $this->artisan('contacts:fix-multiselect-values', ['--dry-run' => true])
        ->expectsOutputToContain("contact #{$this->broken->id}")
        ->expectsOutputToContain("contact #{$this->trashed->id}")
        ->expectsOutputToContain("Organization #{$this->org->id}: 2 value(s) would be fixed.")
        ->assertSuccessful();

    expect([storedCustomFieldValues($this->broken), storedCustomFieldValues($this->trashed), storedCustomFieldValues($this->healthy)])->toBe($before);
});

it('rewrites objects as lists and nothing else', function () {
    Segment::factory()->for($this->org)->published()->create();
    Queue::fake([ResyncContactSegments::class]);
    $healthyBefore = storedCustomFieldValues($this->healthy);
    $updatedAt = $this->broken->fresh()->updated_at;

    $this->artisan('contacts:fix-multiselect-values')
        ->expectsOutputToContain("Organization #{$this->org->id}: 2 value(s) fixed.")
        ->expectsOutputToContain("php artisan segments:sync --organization={$this->org->id}")
        ->assertSuccessful();

    $broken = json_decode(storedCustomFieldValues($this->broken), true);

    expect($broken[$this->field->key])->toBe(['laravel', 'react'])
        ->and($broken[$this->other->key])->toBe('Keep me')
        ->and(json_decode(storedCustomFieldValues($this->trashed), true)[$this->field->key])->toBe(['laravel', 'react'])
        ->and(storedCustomFieldValues($this->healthy))->toBe($healthyBefore)
        ->and($this->broken->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    Queue::assertNotPushed(ResyncContactSegments::class);
});

it('has nothing to do once everything is fixed', function () {
    $this->artisan('contacts:fix-multiselect-values')->assertSuccessful();

    $this->artisan('contacts:fix-multiselect-values')->expectsOutputToContain('Nothing to fix.')->assertSuccessful();
});

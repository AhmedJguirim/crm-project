<?php

use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use App\Services\ContactImportService;
use App\Support\CsvDialect;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->rate = CustomField::factory()->for($this->org)->create(['name' => 'Taux (€)', 'type' => 'number', 'unique' => false, 'order' => 1]);
    $this->start = CustomField::factory()->for($this->org)->create(['name' => 'Début', 'type' => 'date', 'unique' => false, 'order' => 2]);
    $this->interests = CustomField::factory()->for($this->org)->create([
        'name' => 'Intérêts', 'type' => 'multiselect', 'unique' => false, 'order' => 3,
        'options' => [['label' => 'laravel', 'value' => 'laravel'], ['label' => 'php', 'value' => 'php'], ['label' => 'vue', 'value' => 'vue']],
    ]);

    $this->import = function (string $content, bool $windows1252 = false): DatabaseNotification {
        $path = 'contact-imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $windows1252 ? iconv('UTF-8', 'Windows-1252', $content) : $content);

        DatabaseNotification::query()->delete();
        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    };

    $this->contact = fn (string $email): Contact => Contact::forOrganization($this->org->id)->where('email', $email)->firstOrFail();
});

it('imports a file saved by a French Excel', function () {
    $notification = ($this->import)("name;email;phone;tags;Taux (€);Début;Intérêts\nHélène Dupré;helene@example.test;+33612345678;\"VIP;Newsletter\";25,5;16/03/2026;\"laravel;php\"\n", windows1252: true);

    $contact = ($this->contact)('helene@example.test');

    expect($notification->data['body'])->toBe('Imported: 1 | Failed: 0')
        ->and($contact->name)->toBe('Hélène Dupré')
        ->and($contact->customFieldValue($this->rate->key))->toBe(25.5)
        ->and($contact->customFieldValue($this->start->key))->toBe('2026-03-16')
        ->and($contact->customFieldValue($this->interests->key))->toBe(['laravel', 'php'])
        ->and($contact->tags()->withoutGlobalScope('organization')->pluck('name')->sort()->values()->all())->toBe(['Newsletter', 'VIP']);
});

it('also takes German dates and an excel "CSV UTF-8" file with semicolons', function () {
    ($this->import)("name;email;Début\nAnn;ann@example.test;16.03.2026\n");

    expect(($this->contact)('ann@example.test')->customFieldValue($this->start->key))->toBe('2026-03-16');
});

it('takes negative decimal commas and plain numbers', function (string $given, float $stored) {
    ($this->import)("name;email;Taux (€)\nAnn;ann@example.test;\"{$given}\"\n");

    expect(($this->contact)('ann@example.test')->customFieldValue($this->rate->key))->toEqual($stored);
})->with([
    'negative' => ['-3,25', -3.25],
    'integer' => ['42', 42.0],
    'point' => ['2.5', 2.5],
]);

it('still refuses impossible dates', function () {
    $notification = ($this->import)("name;email;Début\nAnn;ann@example.test;31/02/2026\n");

    expect($notification->data['body'])->toContain("Row 1: Invalid value for field 'Début': 31/02/2026")
        ->and(Contact::forOrganization($this->org->id)->count())->toBe(0);
});

it('keeps the strict rules for a comma file', function (string $column, string $value) {
    $notification = ($this->import)("name,email,{$column}\nAnn,ann@example.test,\"{$value}\"\n");

    expect($notification->data['body'])->toContain("Invalid value for field '{$column}': {$value}")
        ->and(Contact::forOrganization($this->org->id)->count())->toBe(0);
})->with([
    'decimal comma' => ['Taux (€)', '25,5'],
    'day first date' => ['Début', '16/03/2026'],
    'dotted date' => ['Début', '16.03.2026'],
]);

it('does not accept thousands separators', function (string $value) {
    $notification = ($this->import)("name;email;Taux (€)\nAnn;ann@example.test;\"{$value}\"\n");

    expect($notification->data['body'])->toContain("Invalid value for field 'Taux (€)': {$value}");
})->with(['1 234,5', '1.234,5', '1,234.5']);

it('writes the failed rows of a European import as a standard csv', function () {
    ($this->import)("name;email\nHélène;not-an-email\n", windows1252: true);

    $report = collect(Storage::disk('local')->files('contact-imports'))->first(fn (string $file): bool => str_contains($file, 'failed-'));
    $contents = Storage::disk('local')->get($report);

    expect($contents)->toStartWith("\xEF\xBB\xBF")
        ->and($contents)->toContain("_row_number,_error,name,email\n")
        ->and($contents)->toContain('Hélène')
        ->and(mb_check_encoding($contents, 'UTF-8'))->toBeTrue();
});

it('imports the failed rows file again as it is', function () {
    ($this->import)("name;email\nHélène;not-an-email\n", windows1252: true);
    $report = collect(Storage::disk('local')->files('contact-imports'))->first(fn (string $file): bool => str_contains($file, 'failed-'));
    $corrected = str_replace('not-an-email', 'helene@example.test', Storage::disk('local')->get($report));

    $notification = ($this->import)($corrected);

    expect($notification->data['body'])->toBe('Imported: 1 | Failed: 0')
        ->and(($this->contact)('helene@example.test')->name)->toBe('Hélène');
});

describe('the service', function () {
    it('only localizes numbers and dates for a European dialect', function (?CsvDialect $dialect, string $date, string $number, bool $accepted) {
        $service = new ContactImportService($this->org->id, $dialect);

        expect($service->parseDate($date) !== false)->toBe($accepted);

        $result = $service->processRow(['name' => 'Ann', 'email' => 'ann'.random_int(1, 99999).'@example.test', 'Taux (€)' => $number], ['Taux (€)' => $this->rate]);

        expect($result['success'])->toBe($accepted);
    })->with([
        'no dialect' => [null, '16/03/2026', '25,5', false],
        'comma dialect' => [new CsvDialect, '16/03/2026', '25,5', false],
        'european dialect' => [new CsvDialect(';'), '16/03/2026', '25,5', true],
    ]);
});

it('tells the upload form that both separators are accepted', function () {
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    Livewire::test(ListContacts::class)
        ->mountAction('importContacts')
        ->assertSchemaComponentExists('file', 'mountedActionSchema0', function (FileUpload $field): bool {
            $helperText = collect($field->getChildComponents($field::BELOW_CONTENT_SCHEMA_KEY))->map(fn ($component): string => (string) $component->getContent())->implode(' ');

            return str_contains($helperText, 'Comma- or semicolon-separated CSV files are accepted.');
        });
});

<?php

use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Support\CustomFields\CustomFieldDefinitionFields;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Services\ContactImportService;
use App\Services\ContactImportTemplate;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function templateField(int $organizationId, string $type, string $name, int $order, array $attributes = []): CustomField
{
    return CustomField::factory()->create([
        'organization_id' => $organizationId,
        'name' => $name,
        'type' => $type,
        'unique' => false,
        'order' => $order,
        ...$attributes,
    ]);
}

function templateCsvContents(int $organizationId): string
{
    $path = ContactImportTemplate::forOrganization($organizationId)->writeCsv();
    $contents = file_get_contents($path);

    unlink($path);

    return $contents;
}

function importTemplateUnchanged(int $organizationId, int $userId): void
{
    $path = 'contact-imports/template-'.uniqid().'.csv';
    Storage::disk('local')->put($path, templateCsvContents($organizationId));

    ProcessContactImportJob::dispatchSync($path, $organizationId, $userId);
}

describe('round trip', function () {
    it('imports the template of an organization with every field type unchanged', function () {
        $fields = [];
        $order = 1;

        foreach (array_keys(CustomFieldDefinitionFields::TYPES) as $type) {
            $name = $type === 'text' ? 'Rate, € / day' : "Field {$type}";
            $attributes = match ($type) {
                'select' => ['options' => [['label' => 'Monthly', 'value' => 'Monthly'], ['label' => 'Per day, flat', 'value' => 'Per day, flat']]],
                'multiselect' => ['options' => [['label' => 'laravel', 'value' => 'laravel'], ['label' => 'php', 'value' => 'php'], ['label' => 'vue', 'value' => 'vue']]],
                default => [],
            };
            $fields[$type] = templateField($this->org->id, $type, $name, $order++, $attributes);
        }

        $trashed = templateField($this->org->id, 'text', 'Old field', $order);
        $trashed->delete();

        importTemplateUnchanged($this->org->id, $this->user->id);

        $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
        $contact = Contact::where('organization_id', $this->org->id)->where('email', 'john@example.com')->first();
        $values = $contact->custom_field_values;

        expect($notification->data['body'])->toContain('Imported: 1 | Failed: 0')
            ->and($contact->tags()->pluck('name')->sort()->values()->all())->toBe(['Newsletter', 'VIP'])
            ->and($values[$fields['text']->key])->toBe('Some text')
            ->and($values[$fields['textarea']->key])->toBe('Some notes')
            ->and($values[$fields['email']->key])->toBe('jane@example.com')
            ->and($values[$fields['url']->key])->toBe('https://example.com')
            ->and($values[$fields['phone']->key])->toBe('+33612345678')
            ->and($values[$fields['number']->key])->toEqual(42)
            ->and($values[$fields['date']->key])->toBe(now()->startOfYear()->addDays(14)->format('Y-m-d'))
            ->and($values[$fields['select']->key])->toBe('Monthly')
            ->and($values[$fields['multiselect']->key])->toEqual(['laravel', 'php'])
            ->and($values)->not->toHaveKey($trashed->key);
    });

    it('imports the template of an organization without custom fields unchanged', function () {
        importTemplateUnchanged($this->org->id, $this->user->id);

        expect(DatabaseNotification::where('notifiable_id', $this->user->id)->first()->data['body'])->toContain('Imported: 1 | Failed: 0')
            ->and(Contact::where('organization_id', $this->org->id)->count())->toBe(1);
    });
});

describe('template file', function () {
    it('quotes values containing commas and quotes', function () {
        templateField($this->org->id, 'text', 'Say "hi", please', 1);

        $path = ContactImportTemplate::forOrganization($this->org->id)->writeCsv();
        $rows = iterator_to_array((new ContactImportFileReader)->rows($path, 'csv'), false);

        unlink($path);

        expect($rows)->toHaveCount(2)
            ->and(count($rows[1]))->toBe(count($rows[0]))
            ->and($rows[0][4])->toBe('Say "hi", please');
    });

    it('starts with a utf-8 byte order mark', function () {
        expect(bin2hex(substr(templateCsvContents($this->org->id), 0, 3)))->toBe('efbbbf');
    });

    it('follows the import column order and leaves out trashed fields', function () {
        templateField($this->org->id, 'text', 'Second', 2);
        templateField($this->org->id, 'text', 'First', 1);
        templateField($this->org->id, 'text', 'Gone', 3)->delete();

        expect(ContactImportTemplate::forOrganization($this->org->id)->headers())->toBe(['name', 'email', 'phone', 'tags', 'First', 'Second'])
            ->and(ContactImportTemplate::forOrganization($this->org->id)->exampleRow()[3])->toBe('VIP;Newsletter');
    });

    it('is returned by the download action', function () {
        Livewire::test(ListContacts::class)
            ->callAction('downloadTemplate')
            ->assertFileDownloaded('contacts-import-template.csv');
    });
});

describe('example values', function () {
    it('are accepted by the import for their field type', function (string $type) {
        $field = templateField($this->org->id, $type, 'Example', 1, in_array($type, CustomFieldDefinitionFields::TYPES_WITH_OPTIONS)
            ? ['options' => [['label' => 'One', 'value' => 'one'], ['label' => 'Two', 'value' => 'two']]]
            : []);
        $service = new ContactImportService($this->org->id);

        $result = $service->processRow(['name' => 'Jane', 'email' => 'jane@example.com', 'Example' => $service->exampleValueFor($field)], ['Example' => $field]);

        expect($result)->toBe(['success' => true, 'error' => null])
            ->and(Contact::where('email', 'jane@example.com')->first()->custom_field_values)->toHaveKey($field->key);
    })->with(array_keys(CustomFieldDefinitionFields::TYPES));

    it('skips multi-select options containing the separator', function () {
        $field = templateField($this->org->id, 'multiselect', 'Skills', 1, ['options' => [
            ['label' => 'a;b', 'value' => 'a;b'], ['label' => 'c', 'value' => 'c'], ['label' => 'd', 'value' => 'd'],
        ]]);

        expect((new ContactImportService($this->org->id))->exampleValueFor($field))->toBe('c;d');
    });

    it('is empty for a select field without options', function () {
        $field = templateField($this->org->id, 'select', 'Plan', 1, ['options' => []]);

        expect((new ContactImportService($this->org->id))->exampleValueFor($field))->toBe('');
    });
});

describe('import modal', function () {
    it('explains the separator and the date formats', function () {
        Livewire::test(ListContacts::class)
            ->mountAction('importContacts')
            ->assertSchemaComponentExists('file', 'mountedActionSchema0', function (FileUpload $field): bool {
                $helperText = collect($field->getChildComponents($field::BELOW_CONTENT_SCHEMA_KEY))->map(fn ($component): string => (string) $component->getContent())->implode(' ');

                return str_contains($helperText, 'dd-mm-yyyy') && str_contains($helperText, 'Separate several tags or multi-select values with ";"');
            });
    });
});

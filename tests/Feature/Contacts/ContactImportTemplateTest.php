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
use Spatie\SimpleExcel\SimpleExcelReader;

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

function templateXlsxContents(int $organizationId): string
{
    $path = ContactImportTemplate::forOrganization($organizationId)->writeXlsx();
    $contents = file_get_contents($path);

    unlink($path);

    return $contents;
}

function importTemplateUnchanged(int $organizationId, int $userId): void
{
    $path = 'contact-imports/template-'.uniqid().'.xlsx';
    Storage::disk('local')->put($path, templateXlsxContents($organizationId));

    ProcessContactImportJob::dispatchSync($path, $organizationId, $userId);
}

/**
 * The number format of a cell of the first sheet: the built-in id (49 is Text), the custom format code, or "no style"
 * when the cell was not written with one.
 */
function templateCellNumberFormat(string $path, string $cell): string|int
{
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles = new SimpleXMLElement($zip->getFromName('xl/styles.xml'));
    $zip->close();

    preg_match('/<c r="'.$cell.'"[^>]*? s="(\d+)"/', $sheet, $matches);

    if (! isset($matches[1])) {
        return 'no style';
    }

    $formatId = (int) $styles->cellXfs->xf[(int) $matches[1]]['numFmtId'];

    foreach ($styles->numFmts->numFmt ?? [] as $format) {
        if ((int) $format['numFmtId'] === $formatId) {
            return (string) $format['formatCode'];
        }
    }

    return $formatId;
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
    it('keeps header values with quotes and commas', function () {
        templateField($this->org->id, 'text', 'Say "hi", please', 1);

        $path = ContactImportTemplate::forOrganization($this->org->id)->writeXlsx();
        $rows = iterator_to_array((new ContactImportFileReader)->rows($path, 'xlsx'), false);

        unlink($path);

        expect($rows)->toHaveCount(2)
            ->and(count($rows[1]))->toBe(count($rows[0]))
            ->and($rows[0][6])->toBe('Say "hi", please');
    });

    it('follows the import column order and leaves out trashed fields', function () {
        templateField($this->org->id, 'text', 'Second', 2);
        templateField($this->org->id, 'text', 'First', 1);
        templateField($this->org->id, 'text', 'Gone', 3)->delete();

        expect(ContactImportTemplate::forOrganization($this->org->id)->headers())->toBe(['name', 'email', 'phone', 'tags', 'status', 'lead source', 'First', 'Second'])
            ->and(ContactImportTemplate::forOrganization($this->org->id)->exampleRow()[3])->toBe('VIP;Newsletter');
    });

    it('has typed cells', function () {
        templateField($this->org->id, 'date', 'Start', 1);
        templateField($this->org->id, 'number', 'Budget', 2);
        templateField($this->org->id, 'phone', 'Mobile', 3);

        $path = ContactImportTemplate::forOrganization($this->org->id)->writeXlsx();
        $rows = SimpleExcelReader::create($path, 'xlsx')->noHeaderRow()->take(2)->getRows()->values()->all();

        unlink($path);

        expect($rows[1][2])->toBe('+1234567890')
            ->and($rows[1][3])->toBe('VIP;Newsletter')
            ->and($rows[1][4])->toBe('Lead')
            ->and($rows[1][5])->toBe('Referral')
            ->and($rows[1][6])->toBeInstanceOf(DateTimeInterface::class)
            ->and($rows[1][6]->format('d-m'))->toBe('15-01')
            ->and($rows[1][6]->format('Y H:i:s'))->toBe(now()->year.' 00:00:00')
            ->and($rows[1][7])->toBe(42)
            ->and($rows[1][8])->toBe('+33612345678');
    });

    it('formats the text and date columns, including the empty rows below the example', function () {
        templateField($this->org->id, 'date', 'Start', 1);
        templateField($this->org->id, 'number', 'Budget', 2);

        $path = ContactImportTemplate::forOrganization($this->org->id)->writeXlsx();
        $formats = [
            'phone, example row' => templateCellNumberFormat($path, 'C2'),
            'phone, last empty row' => templateCellNumberFormat($path, 'C1002'),
            'status, last empty row' => templateCellNumberFormat($path, 'E1002'),
            'start, last empty row' => templateCellNumberFormat($path, 'G1002'),
        ];

        unlink($path);

        expect($formats)->toBe([
            'phone, example row' => 49,
            'phone, last empty row' => 49,
            'status, last empty row' => 49,
            'start, last empty row' => 'dd-mm-yyyy',
        ]);
    });

    it('does not turn the formatted empty rows into contacts', function () {
        templateField($this->org->id, 'date', 'Start', 1);

        importTemplateUnchanged($this->org->id, $this->user->id);

        $body = DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'];

        expect($body)->toBe('Imported: 1 | Failed: 0')
            ->and(Contact::where('organization_id', $this->org->id)->count())->toBe(1);
    });

    it('is returned by the download action', function () {
        Livewire::test(ListContacts::class)
            ->callAction('downloadTemplate')
            ->assertFileDownloaded('contacts-import-template.xlsx');
    });
});

describe('download action', function () {
    it('is labelled as the Excel template', function () {
        Livewire::test(ListContacts::class)
            ->assertActionExists('downloadTemplate', fn ($action): bool => $action->getLabel() === 'Download Excel Template');
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

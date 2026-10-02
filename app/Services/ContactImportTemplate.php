<?php

namespace App\Services;

use App\Models\CustomField;
use App\Support\TemporaryFile;
use Illuminate\Support\Collection;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Builds the contacts import template: the headers the import expects and one example row that imports unchanged.
 */
class ContactImportTemplate
{
    /** @var Collection<int, CustomField>|null */
    private ?Collection $customFields = null;

    public function __construct(
        private readonly ContactImportService $importService,
    ) {}

    public static function forOrganization(int $organizationId): self
    {
        return new self(new ContactImportService($organizationId));
    }

    /** @return array<int, string> */
    public function headers(): array
    {
        return [
            'name',
            'email',
            'phone',
            'tags',
            ...$this->customFields()->map(fn (CustomField $field): string => $field->name)->all(),
        ];
    }

    /** @return array<int, string> */
    public function exampleRow(): array
    {
        return [
            'John Doe',
            'john@example.com',
            '+1234567890',
            implode(ContactImportService::MULTI_VALUE_SEPARATOR, ['VIP', 'Newsletter']),
            ...$this->customFields()->map(fn (CustomField $field): string => $this->importService->exampleValueFor($field))->all(),
        ];
    }

    /**
     * Writes the template as a UTF-8 CSV (with a BOM, so Excel reads accents and symbols right) to a temporary file.
     *
     * @return string The absolute path of the file.
     */
    public function writeCsv(): string
    {
        $path = TemporaryFile::reserve('contacts-template-', 'csv');

        SimpleExcelWriter::create($path)
            ->noHeaderRow()
            ->addRow($this->headers())
            ->addRow($this->exampleRow())
            ->close();

        return $path;
    }

    /** @return Collection<int, CustomField> */
    private function customFields(): Collection
    {
        return $this->customFields ??= $this->importService->customFields();
    }
}

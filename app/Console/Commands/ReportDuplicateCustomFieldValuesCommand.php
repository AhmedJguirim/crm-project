<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ReportDuplicateCustomFieldValuesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'custom-fields:report-duplicates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tell each organization owner which unique custom fields hold the same value more than once';

    /**
     * Execute the console command.
     *
     * Saving through the models refuses duplicates (see EnforcesUniqueCustomFieldValues), so what this finds comes from
     * before that check existed or from writes that skip it. Nothing is fixed here, and the values are not listed.
     */
    public function handle(): int
    {
        Organization::query()->with('owner')->each(function (Organization $organization): void {
            $lines = app(TenantContext::class)->run($organization->getKey(), fn (): array => $this->duplicateLines());

            if ($lines === []) {
                return;
            }

            $this->info("Organization #{$organization->getKey()}: ".implode('; ', $lines));

            if ($organization->owner === null) {
                $this->warn("Organization #{$organization->getKey()} has no owner to tell.");

                return;
            }

            Notification::make()
                ->warning()
                ->title('Duplicate values in unique fields')
                ->body(implode("\n", $lines))
                ->sendToDatabase($organization->owner);
        });

        $this->info('Duplicate custom field values reported.');

        return self::SUCCESS;
    }

    /**
     * One line per unique field of the current organization that holds a value more than once.
     *
     * @return array<int, string>
     */
    private function duplicateLines(): array
    {
        $lines = [];

        foreach (CustomField::query()->where('unique', true)->orderBy('order')->get() as $field) {
            $lines[] = $this->duplicateLine($field->name, Contact::query(), $field->key);
        }

        foreach (CompanyCustomField::query()->where('unique', true)->orderBy('order')->get() as $field) {
            $lines[] = $this->duplicateLine($field->name, Company::query()->where('company_type_id', $field->company_type_id), $field->key);
        }

        return array_values(array_filter($lines));
    }

    /**
     * @param  Builder<Contact|Company>  $records
     */
    private function duplicateLine(string $fieldName, Builder $records, string $key): ?string
    {
        $count = $records
            ->selectRaw('custom_field_values->>? as used_value', [$key])
            ->whereRaw("coalesce(custom_field_values->>?, '') not in ('', '[]')", [$key])
            ->groupBy('used_value')
            ->havingRaw('count(*) > 1')
            ->toBase()
            ->get()
            ->count();

        return $count === 0 ? null : "{$fieldName}: {$count} values used more than once";
    }
}

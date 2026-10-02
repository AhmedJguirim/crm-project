<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\CustomField;
use Illuminate\Console\Command;

/**
 * TEMPORARY one-off data repair (BUG-01). Run it once per environment after the product owner agrees, then delete it
 * together with its test.
 *
 * Multi-select values imported from a cell with an empty part ("laravel;;react") were stored as JSON objects
 * ({"0":"laravel","2":"react"}) instead of lists, so the segment conditions never matched them.
 */
class FixMultiselectCustomFieldValuesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contacts:fix-multiselect-values {--dry-run : List the contacts that would be fixed without changing them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Temporary: rewrite multi-select custom field values stored as JSON objects into lists';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /** @var array<int, int> $fixedByOrganization */
        $fixedByOrganization = [];

        CustomField::withTrashed()
            ->withoutGlobalScopes()
            ->where('type', 'multiselect')
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get()
            ->each(function (CustomField $field) use ($dryRun, &$fixedByOrganization): void {
                Contact::withTrashed()
                    ->withoutGlobalScopes()
                    ->where('organization_id', $field->organization_id)
                    ->whereRaw("jsonb_typeof(custom_field_values -> ?) = 'object'", [$field->key])
                    ->lazyById()
                    ->each(function (Contact $contact) use ($field, $dryRun, &$fixedByOrganization): void {
                        $this->info("Fixing the `{$field->name}` value of contact #{$contact->id} (organization #{$contact->organization_id})...");

                        $fixedByOrganization[$contact->organization_id] = ($fixedByOrganization[$contact->organization_id] ?? 0) + 1;

                        if ($dryRun) {
                            return;
                        }

                        $values = $contact->custom_field_values;
                        $values[$field->key] = array_values($values[$field->key]);

                        $contact->custom_field_values = $values;
                        $contact->timestamps = false;
                        $contact->saveQuietly();
                    });
            });

        foreach ($fixedByOrganization as $organizationId => $count) {
            $this->comment("Organization #{$organizationId}: {$count} value(s) ".($dryRun ? 'would be fixed.' : 'fixed.'));
        }

        if ($fixedByOrganization === []) {
            $this->comment('Nothing to fix.');

            return self::SUCCESS;
        }

        if (! $dryRun) {
            foreach (array_keys($fixedByOrganization) as $organizationId) {
                $this->comment("Now run: php artisan segments:sync --organization={$organizationId}");
            }
        }

        return self::SUCCESS;
    }
}

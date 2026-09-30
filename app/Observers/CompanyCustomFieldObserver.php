<?php

namespace App\Observers;

use App\Models\CompanyCustomField;

class CompanyCustomFieldObserver
{
    public function creating(CompanyCustomField $companyCustomField): void
    {
        if (is_null($companyCustomField->order)) {
            $maxOrder = CompanyCustomField::query()
                ->where('company_type_id', $companyCustomField->company_type_id)
                ->max('order') ?? 0;

            $companyCustomField->order = $maxOrder + 1;
        }
    }
}

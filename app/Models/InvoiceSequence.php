<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\InvoiceSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The highest invoice sequence an organization has handed out in one local year. It only grows (see
 * `InvoiceNumbers`), so a number is never given again after its invoice is deleted.
 */
class InvoiceSequence extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<InvoiceSequenceFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'year',
        'last_sequence',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_sequence' => 'integer',
        ];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\IsCustomField;
use App\Observers\CustomFieldObserver;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([CustomFieldObserver::class])]
class CustomField extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory;

    use IsCustomField;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'options',
        'unique',
        'order',
    ];

    /** @return array{organization_id: int|null} */
    public function keyUniquenessScope(): array
    {
        return ['organization_id' => $this->organization_id];
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'unique' => 'boolean',
            'order' => 'integer',
        ];
    }
}

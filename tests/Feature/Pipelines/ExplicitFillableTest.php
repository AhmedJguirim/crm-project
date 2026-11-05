<?php

use App\Enums\DealStatus;
use App\Models\Address;
use App\Models\CompanyType;
use App\Models\LostReason;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->forged = ['id' => 999999, 'created_at' => '2001-01-01 00:00:00', 'updated_at' => '2001-01-01 00:00:00', 'deleted_at' => '2001-01-01 00:00:00'];
    $this->travelTo(Carbon::parse('2026-11-04 10:00:00'));
});

test('a forged id on a pipeline is not mass assigned', function () {
    $pipeline = Pipeline::query()->create(['organization_id' => $this->org->id, 'name' => 'Sales', 'id' => 999999]);

    expect($pipeline->getKey())->not->toBe(999999)
        ->and($pipeline->organization_id)->toBe($this->org->id)
        ->and($pipeline->name)->toBe('Sales');
});

test('every listed column of the five models is written and the key and timestamps are not', function () {
    $pipeline = Pipeline::query()->create(['organization_id' => $this->org->id, 'name' => 'Sales', ...$this->forged]);

    $stage = PipelineStage::query()->create([
        'organization_id' => $this->org->id,
        'pipeline_id' => $pipeline->id,
        'name' => 'Qualified',
        'type' => DealStatus::Open,
        'color' => '#112233',
        'position' => 7,
        'probability' => 40,
        ...$this->forged,
    ]);

    $address = Address::query()->create([
        'organization_id' => $this->org->id,
        'country' => 'France',
        'city' => 'Lyon',
        'street' => '1 rue X',
        'zip' => '69001',
        ...$this->forged,
    ]);

    $reason = LostReason::query()->create(['organization_id' => $this->org->id, 'name' => 'Too expensive', ...$this->forged]);
    $type = CompanyType::query()->create(['organization_id' => $this->org->id, 'name' => 'Agency', ...$this->forged]);

    expect($stage->fresh())
        ->name->toBe('Qualified')
        ->type->toBe(DealStatus::Open)
        ->color->toBe('#112233')
        ->position->toBe(7)
        ->probability->toBe(40)
        ->pipeline_id->toBe($pipeline->id)
        ->and($address->fresh())
        ->country->toBe('France')
        ->city->toBe('Lyon')
        ->street->toBe('1 rue X')
        ->zip->toBe('69001')
        ->and($reason->fresh()->name)->toBe('Too expensive')
        ->and($type->fresh()->name)->toBe('Agency');

    foreach ([$pipeline, $stage, $address, $reason, $type] as $model) {
        $fresh = $model->fresh();

        expect($fresh->getKey())->not->toBe(999999)
            ->and($fresh->organization_id)->toBe($this->org->id)
            ->and($fresh->created_at->toDateTimeString())->toBe('2026-11-04 10:00:00')
            ->and($fresh->updated_at->toDateTimeString())->toBe('2026-11-04 10:00:00');
    }

    foreach ([$address, $reason, $type] as $softDeleting) {
        expect($softDeleting->fresh()->deleted_at)->toBeNull();
    }
});

test('a forged update cannot move the key or the timestamps of a lost reason', function () {
    $reason = LostReason::query()->create(['organization_id' => $this->org->id, 'name' => 'Too expensive']);

    $reason->update(['name' => 'Budget', ...$this->forged]);

    $fresh = LostReason::query()->withoutGlobalScope('organization')->withTrashed()->where('name', 'Budget')->firstOrFail();

    expect($fresh->getKey())->toBe($reason->getKey())
        ->and($fresh->deleted_at)->toBeNull()
        ->and($fresh->created_at->toDateTimeString())->toBe('2026-11-04 10:00:00');
});

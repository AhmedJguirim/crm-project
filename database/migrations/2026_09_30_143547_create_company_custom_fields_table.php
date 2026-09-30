<?php

use App\Models\CompanyType;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Organization::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(CompanyType::class)->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('type');
            $table->json('options')->nullable();
            $table->boolean('unique')->default(false);
            $table->integer('order')->default(1);
            $table->timestamps();

            $table->unique(['company_type_id', 'name']);
            $table->unique(['company_type_id', 'key']);
            $table->index(['organization_id', 'company_type_id', 'order'], 'company_custom_fields_org_type_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_custom_fields');
    }
};

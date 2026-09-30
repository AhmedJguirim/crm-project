<?php

use App\Models\Address;
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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Organization::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->jsonb('custom_field_values')->default('{}')->nullable();
            $table->foreignIdFor(Address::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(CompanyType::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

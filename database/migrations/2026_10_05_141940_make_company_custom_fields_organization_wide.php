<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_custom_fields', function (Blueprint $table) {
            $table->dropUnique(['company_type_id', 'name']);
            $table->dropUnique(['company_type_id', 'key']);
            $table->dropIndex('company_custom_fields_org_type_order_index');
            $table->dropConstrainedForeignId('company_type_id');

            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'order']);
        });
    }
};

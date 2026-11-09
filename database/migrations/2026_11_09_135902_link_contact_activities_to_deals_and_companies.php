<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_activities', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable()->change();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
            $table->index(['company_id', 'occurred_at']);
            $table->index(['deal_id', 'occurred_at']);
        });

        DB::statement('ALTER TABLE contact_activities ADD CONSTRAINT contact_activities_linked_record_check CHECK (num_nonnulls(contact_id, deal_id, company_id) >= 1)');
    }
};

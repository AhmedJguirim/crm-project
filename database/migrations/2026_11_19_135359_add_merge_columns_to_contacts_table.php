<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('merged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->jsonb('merge_snapshot')->nullable();
            $table->index('merged_into_id');
        });

        DB::statement('ALTER TABLE contacts ADD CONSTRAINT contacts_not_merged_into_itself_check CHECK (merged_into_id IS NULL OR merged_into_id <> id)');
    }
};

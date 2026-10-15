<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 10);
            $table->string('color', 20);
            $table->unsignedInteger('position');
            $table->unsignedSmallInteger('probability');
            $table->timestamps();

            $table->index(['pipeline_id', 'position']);
        });

        DB::statement('ALTER TABLE pipeline_stages ADD CONSTRAINT pipeline_stages_probability_check CHECK (probability BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE pipeline_stages ADD CONSTRAINT pipeline_stages_type_check CHECK (type IN ('open', 'won', 'lost'))");
        DB::statement("CREATE UNIQUE INDEX pipeline_stages_one_won_and_one_lost ON pipeline_stages (pipeline_id, type) WHERE type <> 'open'");
    }
};

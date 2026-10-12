<?php

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
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Organization::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('rules')->default('[]');
            $table->jsonb('draft_rules')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_syncing')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_published']);
        });
    }
};

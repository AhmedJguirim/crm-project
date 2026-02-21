<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->dateTime('occurred_at');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_activities');
    }
};

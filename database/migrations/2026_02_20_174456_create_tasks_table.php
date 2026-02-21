<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);
            $table->dateTime('due_at')->nullable();
            $table->string('type')->default(TaskType::FollowUp->value);
            $table->string('priority')->default(TaskPriority::Medium->value);
            $table->text('notes')->nullable();
            $table->string('status')->default(TaskStatus::Pending->value);
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['organization_id', 'due_at']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'contact_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};

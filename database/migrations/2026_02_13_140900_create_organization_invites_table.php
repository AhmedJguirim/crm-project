<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->string('role')->default('member');
            $table->string('status')->default('pending'); // pending, accepted, expired, revoked
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            // Only one pending invite per email per organization
            $table->unique(['organization_id', 'email', 'status'], 'org_email_pending_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invites');
    }
};

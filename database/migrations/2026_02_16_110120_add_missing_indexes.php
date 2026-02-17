<?php

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
        Schema::table('organization_user', function (Blueprint $table) {
            $table->index(['organization_id', 'role']);
        });
        Schema::table('organization_invites', function (Blueprint $table) {
            $table->index(['organization_id', 'status']);
        });
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->index(['organization_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'role']);
        });
        Schema::table('organization_invites', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
        });
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'type']);
        });
    }
};

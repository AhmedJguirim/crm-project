<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['contacts', 'companies'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->index(['organization_id', 'owner_id']);
            });
        }
    }
};

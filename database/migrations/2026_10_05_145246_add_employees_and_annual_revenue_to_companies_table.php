<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('employees')->nullable()->after('phone');
            $table->decimal('annual_revenue', 15, 2)->nullable()->after('employees');
        });
    }
};

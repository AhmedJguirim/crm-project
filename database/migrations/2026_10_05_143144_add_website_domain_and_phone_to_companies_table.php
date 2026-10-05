<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('website')->nullable()->after('name');
            $table->string('domain')->nullable()->after('website');
            $table->string('phone', 50)->nullable()->after('domain');

            $table->index(['organization_id', 'domain']);
        });
    }
};

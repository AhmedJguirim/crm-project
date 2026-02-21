<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contact_activities', 'follow_up_at')) {
            Schema::table('contact_activities', function (Blueprint $table): void {
                $table->dateTime('follow_up_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contact_activities', 'follow_up_at')) {
            Schema::table('contact_activities', function (Blueprint $table): void {
                $table->dropColumn('follow_up_at');
            });
        }
    }
};

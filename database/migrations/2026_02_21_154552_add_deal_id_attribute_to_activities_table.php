<?php

use App\Models\Deal;
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
        Schema::table('contact_activities', function (Blueprint $table) {
            $table->foreignIdFor(Deal::class)->nullable()->after('outcome');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_activities', function (Blueprint $table) {
            $table->dropColumn('deal_id');
        });
    }
};

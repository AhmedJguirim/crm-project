<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 18, 8)->nullable();
        });

        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_exchange_rate_positive_check CHECK (exchange_rate IS NULL OR exchange_rate > 0)');
    }
};

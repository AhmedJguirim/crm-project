<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms')->default(30)->after('currency');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('amount');
        });
    }
};

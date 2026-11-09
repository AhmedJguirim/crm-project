<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'email']);
            $table->string('email')->nullable()->change();
        });

        DB::statement('CREATE UNIQUE INDEX contacts_organization_id_email_unique ON contacts (organization_id, email) WHERE email IS NOT NULL');
        DB::statement("ALTER TABLE contacts ADD CONSTRAINT contacts_email_not_blank_check CHECK (email <> '')");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contacts ADD CONSTRAINT contacts_email_lowercase_check CHECK (email = lower(email))');
    }
};

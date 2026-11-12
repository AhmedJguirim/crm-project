<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invites', function (Blueprint $table) {
            $table->dropUnique('org_email_pending_unique');
        });

        DB::statement("CREATE UNIQUE INDEX organization_invites_one_pending_per_email ON organization_invites (organization_id, lower(email)) WHERE status = 'pending'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a user must never cascade into rows other people still use: the creator / inviter columns are history.
     */
    public function up(): void
    {
        foreach (['organizations' => 'created_by', 'deals' => 'created_by', 'tasks' => 'created_by', 'organization_invites' => 'invited_by'] as $tableName => $column) {
            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->dropForeign([$column]);
                $table->unsignedBigInteger($column)->nullable()->change();
                $table->foreign($column)->references('id')->on('users')->nullOnDelete();
            });
        }
    }
};

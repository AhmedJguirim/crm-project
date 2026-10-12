<?php

use App\Models\Contact;
use App\Models\Segment;
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
        Schema::create('contact_segment', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Segment::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Contact::class)->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['segment_id', 'contact_id']);
            $table->index('contact_id');
            $table->index(['segment_id', 'created_at']);
        });
    }
};

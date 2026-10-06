<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up notes recorded through the log_followup tool (Week 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('followups', function (Blueprint $table) {
            $table->id();
            $table->string('entry_ref')->unique();    // F-0001, the ID the model sees
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->string('farm_id')->index();
            $table->text('notes');
            $table->string('outcome');
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('followups');
    }
};

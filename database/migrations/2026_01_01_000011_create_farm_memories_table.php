<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the officer decided about a farm (Week 6 Phase 2): one row per resolved
 * approval on an agent run. Decisions, not preferences: nothing here is an
 * inference about the officer, and nothing here was written by the model.
 *
 * Written only by FarmMemoryRecorder, from AgentRunner; read only through the
 * recall_farm_history tool. Retention, deletion and read access are Phase 3:
 * officer_id, run_id and occurred_at are kept so those rules can be applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_memories', function (Blueprint $table) {
            $table->id();
            $table->string('memory_ref')->unique();     // M-0001, the citable handle
            $table->string('farm_id')->index();
            $table->string('officer_id');
            $table->string('run_id')->index();
            $table->enum('kind', ['approved', 'declined', 'lapsed']);
            $table->text('summary');
            $table->json('details');
            $table->timestamp('occurred_at')->index();  // when the decision was made, not when this row was
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_memories');
    }
};

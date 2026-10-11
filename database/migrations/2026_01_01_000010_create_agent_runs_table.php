<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Working state of agent runs (Week 5 Phase 3), so a run suspended for
 * approval can be resumed in a later request.
 *
 * This is working state, not evidence: the trace files under
 * storage/app/agent-runs stay the immutable audit record, and a resume writes
 * a new trace rather than rewriting one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->text('goal');
            $table->string('officer_id');
            $table->string('status')->index();
            $table->string('stop_reason')->nullable();
            $table->string('prompt_version');
            $table->string('model');
            $table->integer('iterations')->default(0);
            $table->integer('consecutive_failures')->default(0);
            $table->json('limits');
            $table->json('messages');
            $table->json('steps');
            $table->json('signatures');
            $table->json('pending')->nullable();
            $table->longText('system_prompt');
            $table->json('declarations');
            $table->text('summary')->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->double('active_seconds')->default(0);
            $table->integer('segment')->default(1);
            $table->string('resumed_from')->nullable();
            $table->json('trace_files');
            // The Week 4 approval panel data (token, summary, preview, expiry)
            // while the run waits on the officer; cleared once decided.
            $table->json('approval')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};

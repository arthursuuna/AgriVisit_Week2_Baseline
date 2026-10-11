<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per officer decision on a held write call (Week 4 Phase 3).
 *
 * Written before the tool executes, then updated with the outcome, so the
 * record of the decision survives a crash during execution. summary_shown and
 * preview_shown are what the officer actually saw, never regenerated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('approval_ref')->unique();      // A-0001
            $table->string('pending_token')->unique();     // one decision per held call, enforced here too
            $table->string('session_id')->nullable()->index();
            $table->string('call_id')->nullable();
            $table->string('tool_name');
            $table->json('arguments');
            $table->text('summary_shown');
            $table->json('preview_shown');
            $table->enum('origin', ['model', 'manual']);
            $table->enum('decision', ['approved', 'rejected', 'expired'])->index();
            $table->string('decided_by');
            $table->timestamp('decided_at');
            $table->text('reject_reason')->nullable();
            $table->string('result_code')->nullable();
            $table->string('result_ref')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_approvals');
    }
};

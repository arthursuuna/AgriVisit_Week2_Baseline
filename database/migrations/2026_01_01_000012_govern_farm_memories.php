<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Week 6 Phase 3: governing farm memory.
 *
 *  - farm_memory_deletions: the audit of deletions. Who deleted which entry,
 *    and when. Not what it said: keeping the content would undo the deletion.
 *  - farm_memories.updated_at goes. An entry is never edited after it is
 *    written, so the column had nothing to say.
 *  - Model-composed free text (schedule_visit.purpose, log_followup.notes) is
 *    removed from entries already written. Memory keeps only the arguments
 *    that are schema-constrained values; the full call stays in tool_approvals.
 */
return new class extends Migration
{
    /** Kept per tool; must match FarmMemoryRecorder::KEPT_ARGUMENTS. */
    private const KEPT = [
        'schedule_visit' => ['farm_id', 'visit_date'],
        'log_followup'   => ['farm_id', 'visit_id', 'outcome'],
    ];

    public function up(): void
    {
        Schema::create('farm_memory_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('memory_ref')->unique();
            $table->string('farm_id')->index();
            $table->string('deleted_by');
            $table->timestamp('deleted_at');
        });

        Schema::table('farm_memories', fn (Blueprint $table) => $table->dropColumn('updated_at'));

        foreach (DB::table('farm_memories')->get() as $row) {
            $details = json_decode($row->details, true);
            $details['arguments'] = array_intersect_key($details['arguments'] ?? [],
                array_flip(self::KEPT[$details['tool'] ?? ''] ?? ['farm_id']));

            DB::table('farm_memories')->where('id', $row->id)->update(['details' => json_encode($details)]);
        }
    }

    public function down(): void
    {
        Schema::table('farm_memories', fn (Blueprint $table) => $table->timestamp('updated_at')->nullable());
        Schema::dropIfExists('farm_memory_deletions');
    }
};

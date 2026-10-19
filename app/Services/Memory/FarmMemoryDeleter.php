<?php

namespace App\Services\Memory;

use App\Models\FarmMemory;
use App\Models\FarmMemoryDeletion;
use Illuminate\Support\Facades\DB;

/**
 * An officer deleting a memory entry (Week 6 Phase 3).
 *
 * Removes the entry, so no future recall returns it, and records who deleted
 * which entry and when. It deliberately touches nothing else:
 *   - trace files are write-once evidence of what the agent was told at the
 *     time; a trace that cites M-0001 keeps citing it;
 *   - tool_approvals is the approval audit; the decision, and the officer's
 *     reason, stay on file there;
 *   - agent_runs keeps the conversation of any run that already recalled the
 *     entry, so a run waiting for approval resumes with what it was shown.
 * Memory is deletable; the record of what the agent was told is not.
 */
class FarmMemoryDeleter
{
    public function delete(FarmMemory $memory): FarmMemoryDeletion
    {
        return DB::transaction(function () use ($memory) {
            $deletion = FarmMemoryDeletion::create([
                'memory_ref' => $memory->memory_ref,
                'farm_id'    => $memory->farm_id,
                'deleted_by' => (string) config('agrivisit.officer.id'),
                'deleted_at' => now(),
            ]);

            $memory->delete();

            return $deletion;
        });
    }
}

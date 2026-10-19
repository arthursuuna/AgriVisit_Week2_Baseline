<?php

namespace App\Http\Controllers;

use App\Models\AgentRunRecord;
use App\Models\FarmMemory;
use App\Models\FarmMemoryDeletion;
use App\Services\Memory\FarmMemoryDeleter;
use App\Services\Tools\Catalogue\FarmProfileLookupTool;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The officer's memory view (Week 6 Phase 3): everything the assistant can
 * recall about one farm, what is held but no longer recalled, and what was
 * deleted. Reads farm_memories directly; the assistant reaches the same rows
 * only through recall_farm_history.
 */
class FarmMemoryController extends Controller
{
    public function show(string $farmId): View
    {
        abort_unless(preg_match('/' . FarmProfileLookupTool::FARM_ID_PATTERN . '/', $farmId) === 1, 404);

        $entries = FarmMemory::where('farm_id', $farmId)->orderByDesc('occurred_at')->orderByDesc('id')->get();

        return view('memory.show', [
            'farmId'     => $farmId,
            'recallable' => $entries->filter->isRecallable()->values(),
            'expired'    => $entries->reject->isRecallable()->values(),
            'deletions'  => FarmMemoryDeletion::where('farm_id', $farmId)->orderByDesc('deleted_at')->get(),
            'runs'       => AgentRunRecord::whereIn('run_id', $entries->pluck('run_id'))->pluck('run_id')->flip(),
            'months'     => (int) config('agrivisit.memory.retention_months'),
        ]);
    }

    public function destroy(string $farmId, string $memoryRef, FarmMemoryDeleter $deleter): RedirectResponse
    {
        $memory = FarmMemory::where('farm_id', $farmId)->where('memory_ref', $memoryRef)->firstOrFail();

        $deleter->delete($memory);

        return redirect()->route('memory.show', $farmId)->with('deleted', $memoryRef);
    }
}

<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\FarmMemoryController;
use App\Http\Controllers\ToolConsoleController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChecklistController::class, 'index'])->name('checklist.index');
Route::post('/draft', [ChecklistController::class, 'draft'])->name('checklist.draft');

Route::get('/tools', [ToolConsoleController::class, 'index'])->name('tools.index');
Route::post('/tools/run', [ToolConsoleController::class, 'run'])->name('tools.run');
Route::post('/tools/approve', [ToolConsoleController::class, 'approve'])->name('tools.approve');
Route::post('/tools/ask', [ToolConsoleController::class, 'ask'])->name('tools.ask');
Route::post('/tools/ask/approve', [ToolConsoleController::class, 'askApprove'])->name('tools.ask.approve');
Route::get('/tools/audit', [ToolConsoleController::class, 'audit'])->name('tools.audit');

// Week 5: the agent. Decisions reuse the Week 4 approval panel and ApprovalService.
Route::get('/agent', [AgentController::class, 'index'])->name('agent.index');
Route::post('/agent', [AgentController::class, 'start'])->name('agent.start');
Route::get('/agent/runs', [AgentController::class, 'runs'])->name('agent.runs.index');
Route::get('/agent/runs/{run}', [AgentController::class, 'show'])->name('agent.runs.show');
Route::post('/agent/runs/{run}/decision', [AgentController::class, 'decide'])->name('agent.runs.decide');
Route::get('/agent/runs/{run}/trace/{file}', [AgentController::class, 'trace'])->name('agent.runs.trace')
    ->where('file', '[A-Za-z0-9._-]+\.json');

// Week 6: what the assistant can recall about a farm, and deleting it.
Route::get('/farms/{farmId}/memory', [FarmMemoryController::class, 'show'])->name('memory.show');
Route::delete('/farms/{farmId}/memory/{memoryRef}', [FarmMemoryController::class, 'destroy'])->name('memory.destroy')
    ->where('memoryRef', 'M-[0-9]+');

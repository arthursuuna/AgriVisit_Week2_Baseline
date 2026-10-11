<?php

use App\Http\Controllers\ChecklistController;
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

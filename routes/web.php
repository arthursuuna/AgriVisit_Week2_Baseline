<?php

use App\Http\Controllers\ChecklistController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChecklistController::class, 'index'])->name('checklist.index');
Route::post('/draft', [ChecklistController::class, 'draft'])->name('checklist.draft');

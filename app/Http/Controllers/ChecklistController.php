<?php

namespace App\Http\Controllers;

use App\Services\Checklist\ChecklistDrafter;
use App\Services\Checklist\DraftResult;
use App\Support\FarmProfileRepository;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChecklistController extends Controller
{
    public function __construct(
        private readonly FarmProfileRepository $farms,
        private readonly ChecklistDrafter $drafter,
    ) {
    }

    public function index(): View
    {
        return view('checklist.index', [
            'farms'  => $this->farms->all(),
            'result' => null,
            'farmId' => null,
            'notes'  => '',
        ]);
    }

    public function draft(Request $request): View
    {
        $validated = $request->validate([
            'farm_id' => ['required', 'string'],
            'notes'   => ['nullable', 'string', 'max:2000'],
        ]);

        $farm = $this->farms->find($validated['farm_id']);

        if ($farm === null) {
            return view('checklist.index', [
                'farms'  => $this->farms->all(),
                'result' => DraftResult::failed(
                    'That farm profile was not found.',
                    config('agrivisit.prompt_version'),
                    'n/a'
                ),
                'farmId' => $validated['farm_id'],
                'notes'  => $validated['notes'] ?? '',
            ]);
        }

        $result = $this->drafter->draft($farm, $validated['notes'] ?? '');

        return view('checklist.index', [
            'farms'  => $this->farms->all(),
            'result' => $result,
            'farm'   => $farm,
            'farmId' => $validated['farm_id'],
            'notes'  => $validated['notes'] ?? '',
        ]);
    }
}

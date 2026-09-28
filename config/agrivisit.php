<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Foundation model
    |--------------------------------------------------------------------------
    | Sampling settings are configuration, not incidental defaults. They are
    | recorded in every trace so an evaluation result can be tied to the exact
    | model and settings that produced it (Lecture 3, slide 30).
    */

    'llm' => [
        'provider'    => env('LLM_PROVIDER', 'google'),
        'model'       => env('LLM_MODEL', 'gemini-3.5-flash'),
        'temperature' => (float) env('LLM_TEMPERATURE', 0.2),
        'max_tokens'  => (int) env('LLM_MAX_TOKENS', 1500),
        'timeout'     => (int) env('LLM_TIMEOUT', 45),

        'anthropic' => [
            'key'     => env('ANTHROPIC_API_KEY'),
            'base'    => 'https://api.anthropic.com/v1/messages',
            'version' => '2023-06-01',
        ],

        'openai' => [
            'key'  => env('OPENAI_API_KEY'),
            'base' => 'https://api.openai.com/v1/chat/completions',
        ],

        // Gemini addresses the model in the URL path, so this is the collection
        // base; GoogleClient appends '/{model}:generateContent'.
        'google' => [
            'key'  => env('GOOGLE_API_KEY'),
            'base' => 'https://generativelanguage.googleapis.com/v1beta/models',

            // Gemini 3 models always reason, and those thinking tokens are spent
            // from max_tokens before any answer is written. The level is therefore
            // a sampling setting like temperature, and is recorded in every trace.
            'thinking_level' => env('GOOGLE_THINKING_LEVEL', 'low'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Active prompt version
    |--------------------------------------------------------------------------
    | Changing this changes system behaviour, so it is configuration and is
    | recorded in every trace.
    */

    'prompt_version' => env('PROMPT_VERSION', 'v1.1'),

    'checklist' => [
        'min_items' => 5,
        'max_items' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Restricted topics (AI Boundary Matrix, Week 1 section 4)
    |--------------------------------------------------------------------------
    | The trigger definition lives in deterministic configuration, never in the
    | prompt. This is the seed of the Week 4 guardrail engine.
    */

    'restricted' => [
        'dosing' => [
            'dose', 'dosage', 'dosing', 'application rate', 'ml per', 'ml/l',
            'litres per acre', 'liters per acre', 'kg per hectare', 'kg/ha',
            'how much pesticide', 'how much herbicide', 'how much fungicide',
            'spray rate', 'mixing ratio', 'concentration per',
        ],
        'clinical' => [
            'diagnose', 'diagnosis', 'prescribe', 'prescription',
            'is my cow sick', 'medicine for', 'dewormer dose',
            'antibiotic for', 'vaccinate my', 'treat my animal',
        ],
    ],

    'trace_path' => storage_path('app/traces'),
];

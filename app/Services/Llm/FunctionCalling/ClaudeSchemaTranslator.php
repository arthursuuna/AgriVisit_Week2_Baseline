<?php

namespace App\Services\Llm\FunctionCalling;

use App\Services\Tools\ToolContract;

/**
 * Turns a tool into a Claude tool declaration.
 *
 * Claude's input_schema is full JSON Schema, so the schema is passed through
 * unchanged: pattern, format, minLength and maxLength reach the model as the
 * rules themselves rather than as sentences folded into descriptions, which is
 * what SchemaTranslator has to do for Gemini. The schema is then enforced in
 * three places: the catalogue, the gate, and the model's own declaration.
 */
class ClaudeSchemaTranslator
{
    public function toToolDeclaration(ToolContract $tool): array
    {
        return [
            'name'         => $tool->name(),
            'description'  => $tool->description(),
            'input_schema' => $tool->inputSchema(),
        ];
    }

    /**
     * @param iterable<ToolContract> $tools
     * @return list<array>
     */
    public function toToolDeclarations(iterable $tools): array
    {
        $declarations = [];

        foreach ($tools as $tool) {
            $declarations[] = $this->toToolDeclaration($tool);
        }

        return $declarations;
    }
}

<?php

namespace App\Services\Llm\FunctionCalling;

use App\Services\Tools\ToolContract;

/**
 * Turns a tool's inputSchema() into a Gemini function declaration.
 *
 * The declaration is generated, never hand-written, so the model is told about
 * exactly the parameters the gate validates. Gemini accepts only an OpenAPI
 * subset, so the keywords it rejects are not simply dropped: each is folded
 * into the property's description, and the model still learns the rule the
 * gate will enforce.
 */
class SchemaTranslator
{
    /** Keywords Gemini accepts in a declaration's parameter schema. */
    public const SUPPORTED = ['type', 'description', 'properties', 'required', 'enum', 'items'];

    public function toFunctionDeclaration(ToolContract $tool): array
    {
        return [
            'name'        => $tool->name(),
            'description' => $tool->description(),
            'parameters'  => $this->translate($tool->inputSchema()),
        ];
    }

    private function translate(array $schema): array
    {
        $types    = array_values(array_filter((array) ($schema['type'] ?? 'string'), fn ($t) => $t !== 'null'));
        $nullable = in_array('null', (array) ($schema['type'] ?? []), true);
        $out      = ['type' => strtoupper($types[0] ?? 'string')];

        $rules = $this->rules($schema, $nullable);
        $text  = trim(implode(' ', array_filter([$schema['description'] ?? null, ...$rules])));

        if ($text !== '') {
            $out['description'] = $text;
        }

        if (isset($schema['enum'])) {
            $out['enum'] = $schema['enum'];
        }

        if (isset($schema['properties'])) {
            $out['properties'] = array_map(fn (array $property) => $this->translate($property), $schema['properties']);

            if (! empty($schema['required'])) {
                $out['required'] = array_values($schema['required']);
            }
        }

        if (isset($schema['items'])) {
            $out['items'] = $this->translate($schema['items']);
        }

        return $out;
    }

    /**
     * The unsupported keywords, as sentences.
     *
     * @return list<string>
     */
    private function rules(array $schema, bool $nullable): array
    {
        $rules = [];

        if (isset($schema['pattern'])) {
            $rules[] = $this->describePattern($schema['pattern']);
        }

        if (($schema['format'] ?? null) === 'date') {
            $rules[] = 'Format: YYYY-MM-DD.';
        } elseif (isset($schema['format'])) {
            $rules[] = "Format: {$schema['format']}.";
        }

        $min = $schema['minLength'] ?? null;
        $max = $schema['maxLength'] ?? null;

        if ($min !== null && $min > 1 && $max !== null) {
            $rules[] = "Between {$min} and {$max} characters.";
        } else {
            if ($min === 1) {
                $rules[] = 'Must not be empty.';
            } elseif ($min !== null) {
                $rules[] = "At least {$min} characters.";
            }

            if ($max !== null) {
                $rules[] = "Maximum {$max} characters.";
            }
        }

        $low  = $schema['minimum'] ?? null;
        $high = $schema['maximum'] ?? null;

        $rules[] = match (true) {
            $low !== null && $high !== null => "Between {$low} and {$high}.",
            $low !== null                   => "At least {$low}.",
            $high !== null                  => "At most {$high}.",
            default                         => null,
        };

        if ($nullable) {
            $rules[] = 'May be null.';
        }

        return array_values(array_filter($rules));
    }

    /**
     * "^UG-[A-Z]{3}-\d{3}$" becomes "Format: UG-XXX-000 (X = capital letter,
     * 0 = digit)." Anything beyond literals, [A-Z] and \d is quoted as-is.
     */
    private function describePattern(string $pattern): string
    {
        $body = preg_replace('/^\^|\$$/', '', $pattern);
        $mask = '';
        $legend = [];

        while ($body !== '') {
            if (preg_match('/^(\[A-Z\]|\\\\d)(?:\{(\d+)(,)?\})?/', $body, $m)) {
                $symbol = $m[1] === '[A-Z]' ? 'X' : '0';
                $count  = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;
                $mask  .= str_repeat($symbol, $count) . (! empty($m[3]) ? '…' : '');
                $legend[$symbol] = $symbol === 'X' ? 'X = capital letter' : '0 = digit';
                $body = substr($body, strlen($m[0]));
            } elseif (preg_match('/^[A-Za-z0-9 _-]/', $body, $m)) {
                $mask .= $m[0];
                $body = substr($body, 1);
            } else {
                return "Must match the regular expression {$pattern}.";
            }
        }

        return "Format: {$mask}" . ($legend ? ' (' . implode(', ', $legend) . ')' : '') . '.';
    }
}

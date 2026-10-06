<?php

namespace App\Services\Tools;

use DateTimeImmutable;

/**
 * Validates tool arguments and tool output against the tool's own schema.
 *
 * Deliberately small: object, string, integer, number, boolean, array and
 * null types; required; enum; pattern; minLength/maxLength; minimum/maximum;
 * items; and format: date (Y-m-d). Keywords it does not know, such as
 * description, are ignored so the same schema can be shown to the model.
 */
class SchemaValidator
{
    /** @return list<string> every problem found, empty when valid */
    public function validate(array $schema, array $value): array
    {
        $errors = [];
        $this->check($schema, $value, '', $errors);

        return $errors;
    }

    private function check(array $schema, mixed $value, string $path, array &$errors): void
    {
        $label = $path === '' ? 'Input' : "Parameter '{$path}'";

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];

            if (! $this->matchesAny($types, $value)) {
                $errors[] = sprintf('%s: expected %s, received %s.',
                    $label, implode(' or ', $types), $this->describe($value));

                return;
            }
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = sprintf('%s: %s is not one of [%s].',
                $label, json_encode($value), implode(', ', $schema['enum']));
        }

        match (true) {
            is_string($value)                  => $this->checkString($schema, $value, $label, $errors),
            is_int($value) || is_float($value) => $this->checkNumber($schema, $value, $label, $errors),
            is_array($value) && $this->isObject($schema, $value)
                                               => $this->checkObject($schema, $value, $path, $errors),
            is_array($value)                   => $this->checkArray($schema, $value, $path, $errors),
            default                            => null,
        };
    }

    private function checkString(array $schema, string $value, string $label, array &$errors): void
    {
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = "{$label}: must be at least {$schema['minLength']} characters, received {$length}.";
        }

        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = "{$label}: must be at most {$schema['maxLength']} characters, received {$length}.";
        }

        if (isset($schema['pattern'])) {
            $regex = '#' . str_replace('#', '\#', $schema['pattern']) . '#u';

            if (preg_match($regex, $value) !== 1) {
                $errors[] = "{$label}: '{$value}' does not match the pattern {$schema['pattern']}.";
            }
        }

        if (($schema['format'] ?? null) === 'date' && ! $this->isDate($value)) {
            $errors[] = "{$label}: '{$value}' is not a valid date in YYYY-MM-DD form.";
        }
    }

    private function checkNumber(array $schema, int|float $value, string $label, array &$errors): void
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$label}: must be at least {$schema['minimum']}, received {$value}.";
        }

        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "{$label}: must be at most {$schema['maximum']}, received {$value}.";
        }
    }

    private function checkObject(array $schema, array $value, string $path, array &$errors): void
    {
        $properties = $schema['properties'] ?? [];

        foreach ($schema['required'] ?? [] as $name) {
            if (! array_key_exists($name, $value)) {
                $errors[] = sprintf("Missing required parameter '%s'.", $this->join($path, $name));
            }
        }

        foreach ($value as $name => $item) {
            if (! array_key_exists($name, $properties)) {
                // The model must not be able to smuggle extra fields past the gate.
                $errors[] = sprintf("Unexpected parameter '%s'.", $this->join($path, (string) $name));

                continue;
            }

            $this->check($properties[$name], $item, $this->join($path, (string) $name), $errors);
        }
    }

    private function checkArray(array $schema, array $value, string $path, array &$errors): void
    {
        if (! isset($schema['items'])) {
            return;
        }

        foreach ($value as $i => $item) {
            $this->check($schema['items'], $item, "{$path}[{$i}]", $errors);
        }
    }

    private function matchesAny(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'object'  => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array'   => is_array($value) && array_is_list($value),
                'string'  => is_string($value),
                'integer' => is_int($value),
                'number'  => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null'    => $value === null,
                default   => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /** An empty PHP array is both [] and {}; the schema decides which it is. */
    private function isObject(array $schema, array $value): bool
    {
        if ($value !== []) {
            return ! array_is_list($value);
        }

        return in_array('object', (array) ($schema['type'] ?? []), true);
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        // Round-tripping rejects dates PHP would silently roll over, e.g. 2026-02-30.
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            $value === null   => 'null',
            is_bool($value)   => 'boolean',
            is_int($value)    => 'integer',
            is_float($value)  => 'number',
            is_string($value) => 'string',
            is_array($value)  => $value !== [] && array_is_list($value) ? 'array' : 'object',
            default           => get_debug_type($value),
        };
    }

    private function join(string $path, string $name): string
    {
        return $path === '' ? $name : "{$path}.{$name}";
    }
}

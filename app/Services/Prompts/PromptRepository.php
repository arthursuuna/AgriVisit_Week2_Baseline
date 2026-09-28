<?php

namespace App\Services\Prompts;

use RuntimeException;

/**
 * Loads versioned prompt templates from resources/prompts.
 *
 * Prompts are stored as files rather than inline strings so that a change is a
 * reviewable diff and an evaluation result can name the exact version that
 * produced it (Lecture 3, slide 30).
 */
class PromptRepository
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<string, string> $replacements Placeholder => value.
     */
    public function render(string $family, string $version, string $part, array $replacements = []): string
    {
        $path = sprintf('%s/%s/%s/%s.md', rtrim($this->basePath, '/'), $family, $version, $part);

        if (! is_file($path)) {
            throw new RuntimeException("Prompt template not found: {$path}");
        }

        $template = file_get_contents($path);

        foreach ($replacements as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        return $template;
    }

    /** @return list<string> */
    public function versions(string $family): array
    {
        $dir = sprintf('%s/%s', rtrim($this->basePath, '/'), $family);

        if (! is_dir($dir)) {
            return [];
        }

        $versions = array_values(array_filter(
            scandir($dir),
            fn ($entry) => $entry !== '.' && $entry !== '..' && is_dir($dir . '/' . $entry)
        ));

        sort($versions);

        return $versions;
    }
}

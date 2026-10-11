<?php

namespace App\Services\Llm\FunctionCalling;

/**
 * One tool_use block: the call the model proposed, not a call that ran.
 */
final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {
    }

    /**
     * Identity of the call for repeat detection. The tools are deterministic,
     * so the same name and arguments cannot return anything new. Keys are
     * sorted first: {a:1,b:2} and {b:2,a:1} are the same call.
     */
    public function signature(): string
    {
        $args = $this->arguments;
        $this->sortDeep($args);

        return sha1($this->name . '|' . json_encode($args));
    }

    private function sortDeep(array &$value): void
    {
        // Lists keep their order: [x, y] and [y, x] are different arguments.
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortDeep($item);
            }
        }
    }
}

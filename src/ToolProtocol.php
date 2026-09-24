<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform;

/**
 * The text contract that stands in for native tool calling on an opencode server.
 *
 * The server's message endpoint has no `tools` parameter for the *caller's* tools. Its own `tools`
 * field is the opposite thing: the switch for the tools the server's agent would run on its own
 * host, which this bridge keeps off. So a caller's tools are described in the system prompt and the
 * model is asked to answer with tagged blocks, which are parsed back into real
 * {@see \Symfony\AI\Platform\Result\ToolCall} objects by {@see ResultConverter}.
 *
 * Both halves live here, because a change to the wording the model is given is a change to what
 * comes back, and splitting them across two classes invites exactly the drift that silently breaks
 * parsing.
 *
 * This is emulation, and its reliability depends on the model: a model that ignores the format
 * answers in prose, which the converter returns as text. Nothing is lost, but the caller's tool is
 * not called.
 */
class ToolProtocol
{
    /**
     * Opening tag of a tool call block.
     */
    public const TAG_OPEN = '<tool_call>';

    /**
     * Closing tag of a tool call block.
     */
    public const TAG_CLOSE = '</tool_call>';

    /**
     * Matches one tool call block and captures its JSON body, across lines, non-greedily.
     */
    public const PATTERN = '~<tool_call>\s*(.*?)\s*</tool_call>~s';

    /**
     * The instruction block appended to the system prompt when tools are offered.
     *
     * Written flatly on purpose: the shape is the same one most model families have been trained on
     * for text-mode tool use, and the rules spell out the failure modes that cost a whole turn —
     * prose wrapped around the block, markdown fences, invented tool names, several calls crammed
     * into one block.
     *
     * @param array<mixed> $tools Tool definitions as the platform's contract normalized them,
     *        i.e. `{type: function, function: {name, description, parameters}}`
     * @return string
     */
    public static function instructions(array $tools): string
    {
        $described = [];
        foreach (self::describe($tools) as $name => $tool) {
            $described[] = sprintf(
                "- %s: %s\n  arguments (JSON Schema): %s",
                $name,
                $tool['description'] !== '' ? $tool['description'] : '(no description)',
                json_encode($tool['parameters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        }

        if ($described === []) {
            return '';
        }

        return "# Tools\n\n"
            . "You can call the following tools. They run in the calling application, not here.\n\n"
            . implode("\n", $described) . "\n\n"
            . "To call a tool, reply with one block per call and nothing else:\n\n"
            . self::TAG_OPEN . '{"name": "<tool name>", "arguments": {<arguments as JSON>}}' . self::TAG_CLOSE . "\n\n"
            . "Rules:\n"
            . "- Use a tool only when you need its result to answer. Otherwise reply normally.\n"
            . "- Call only the tools listed above, spelled exactly as above.\n"
            . "- One JSON object per block. To make several calls, emit several blocks.\n"
            . "- Emit the blocks raw: no markdown code fences, no commentary around them.\n"
            . "- The result comes back as a \"Tool result\" message. Use it to answer, or call again.";
    }

    /**
     * Render an assistant turn that asked for tools back into the transcript.
     *
     * Each call is written in exactly the syntax the model is asked to produce, so a multi-turn
     * exchange reads as its own earlier output rather than as a paraphrase of it.
     *
     * @param string $text Any prose the assistant wrote alongside the calls
     * @param array<mixed> $toolCalls As the platform's contract normalized them
     * @return string
     */
    public static function renderToolCalls(string $text, array $toolCalls): string
    {
        $blocks = [];
        foreach ($toolCalls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : $call;
            $name = is_string($function['name'] ?? null) ? $function['name'] : '';
            if ($name === '') {
                continue;
            }

            $blocks[] = self::TAG_OPEN . json_encode(
                ['name' => $name, 'arguments' => self::argumentsOf($function)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . self::TAG_CLOSE;
        }

        return trim($text . "\n" . implode("\n", $blocks));
    }

    /**
     * Render a tool result message into the transcript.
     *
     * Named after the tool where possible rather than only its call id: the id is meaningless to a
     * model that never saw one on the wire, while the name is what it asked for.
     *
     * @param array<mixed> $message A `tool` role message
     * @return string
     */
    public static function renderToolResult(array $message): string
    {
        $name = is_string($message['name'] ?? null) && $message['name'] !== '' ? $message['name'] : null;
        $content = $message['content'] ?? '';
        $content = is_string($content) ? $content : (string) json_encode($content, JSON_UNESCAPED_SLASHES);

        return $name !== null
            ? sprintf('Tool result for %s: %s', $name, $content)
            : sprintf('Tool result: %s', $content);
    }

    /**
     * Normalize offered tool definitions to `name => [description, parameters]`.
     *
     * Accepts both the OpenAI-style envelope the platform's ToolNormalizer produces and a flat
     * `{name, description, parameters}` array, because a caller reaching the platform directly may
     * pass either and neither is worth rejecting over.
     *
     * @param array<mixed> $tools
     * @return array<string,array{description:string,parameters:array<mixed>}>
     */
    public static function describe(array $tools): array
    {
        $described = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $definition = is_array($tool['function'] ?? null) ? $tool['function'] : $tool;
            $name = is_string($definition['name'] ?? null) ? $definition['name'] : '';
            if ($name === '') {
                continue;
            }

            $parameters = is_array($definition['parameters'] ?? null)
                ? $definition['parameters']
                : ['type' => 'object', 'properties' => new \stdClass()];

            $described[$name] = [
                'description' => is_string($definition['description'] ?? null) ? $definition['description'] : '',
                'parameters' => $parameters,
            ];
        }

        return $described;
    }

    /**
     * The arguments off a normalized tool call, decoded when the provider shape carries a string.
     *
     * @param array<mixed> $function
     * @return array<mixed>
     */
    private static function argumentsOf(array $function): array
    {
        $arguments = $function['arguments'] ?? [];
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : [];
        }

        return is_array($arguments) ? $arguments : [];
    }
}

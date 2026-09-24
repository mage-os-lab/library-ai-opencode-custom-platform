<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform;

use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Reads the token counts the server reports on the assistant message (`info.tokens`).
 *
 * The server aggregates what the upstream provider reported for the whole agent turn. What its
 * `input` count includes — in particular whether cache reads are inside it — follows the upstream
 * provider and opencode's own accounting, and has not been verified here; the numbers are passed
 * on as reported.
 */
class TokenUsageExtractor implements TokenUsageExtractorInterface
{
    /**
     * @inheritdoc
     *
     * @param array<string,mixed> $options
     */
    public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
    {
        $info = $rawResult->getData()['info'] ?? null;
        if (!is_array($info) || isset($info['error'])) {
            return null;
        }

        $tokens = $info['tokens'] ?? null;
        if (!is_array($tokens)) {
            return null;
        }
        $cache = is_array($tokens['cache'] ?? null) ? $tokens['cache'] : [];

        return new TokenUsage(
            promptTokens: $this->count($tokens['input'] ?? null),
            completionTokens: $this->count($tokens['output'] ?? null),
            thinkingTokens: $this->count($tokens['reasoning'] ?? null),
            cacheCreationTokens: $this->count($cache['write'] ?? null),
            cacheReadTokens: $this->count($cache['read'] ?? null),
            totalTokens: $this->count($tokens['total'] ?? null),
        );
    }

    /**
     * A reported count as an integer; the schema types these as JSON numbers.
     *
     * @param mixed $value
     * @return int|null
     */
    private function count(mixed $value): ?int
    {
        return is_int($value) || is_float($value) ? (int) $value : null;
    }
}

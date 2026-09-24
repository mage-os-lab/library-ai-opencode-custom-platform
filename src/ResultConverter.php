<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform;

use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * Reads the assistant message an opencode server answers a prompt with.
 *
 * The server reports a failed model call as a *successful* HTTP response: status 200, with the
 * provider's error under `info.error`. So that field is checked first, and turned into the same
 * exceptions every other bridge throws, or a rejected API key upstream would come back as an
 * empty answer.
 */
class ResultConverter implements ResultConverterInterface
{
    /**
     * @inheritdoc
     */
    public function supports(Model $model): bool
    {
        return $model instanceof OpenCodeServerModel;
    }

    /**
     * @inheritdoc
     *
     * @param array<string,mixed> $options
     */
    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $data = $result->getData();
        $info = is_array($data['info'] ?? null) ? $data['info'] : [];

        if (is_array($info['error'] ?? null)) {
            throw $this->toException($info['error']);
        }

        $text = $this->textOf(is_array($data['parts'] ?? null) ? $data['parts'] : []);

        $converted = ($options['stream'] ?? false)
            ? new StreamResult((static function () use ($text): \Generator {
                if ($text !== '') {
                    yield new TextDelta($text);
                }
            })())
            : new TextResult($text);

        $finish = $info['finish'] ?? null;
        if (is_string($finish) && $finish !== '') {
            $converted->getMetadata()->add('finish_reason', new FinishReason($this->toFinishCase($finish), $finish));
        }

        return $converted;
    }

    /**
     * @inheritdoc
     */
    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return new TokenUsageExtractor();
    }

    /**
     * The answer: every text part the model wrote, in order.
     *
     * Synthetic and ignored parts are the server's own bookkeeping (context it injected, parts it
     * later discarded), not something the model said.
     *
     * @param array<mixed> $parts
     * @return string
     */
    private function textOf(array $parts): string
    {
        $texts = [];
        foreach ($parts as $part) {
            if (!is_array($part) || ($part['type'] ?? null) !== 'text' || !is_string($part['text'] ?? null)) {
                continue;
            }
            if (!empty($part['synthetic']) || !empty($part['ignored'])) {
                continue;
            }
            $texts[] = $part['text'];
        }

        return trim(implode("\n", $texts));
    }

    /**
     * The platform exception matching the server's error, keeping its message.
     *
     * @param array<mixed> $error `{name, data: {message, statusCode?}}`
     * @return \Throwable
     */
    private function toException(array $error): \Throwable
    {
        $name = is_string($error['name'] ?? null) ? $error['name'] : 'UnknownError';
        $details = is_array($error['data'] ?? null) ? $error['data'] : [];
        $message = is_string($details['message'] ?? null) && $details['message'] !== ''
            ? $details['message']
            : $name;
        $status = is_int($details['statusCode'] ?? null) ? $details['statusCode'] : null;

        return match (true) {
            $name === 'ProviderAuthError', $status === 401, $status === 403
                => new AuthenticationException($message),
            $name === 'ContextOverflowError' => new ExceedContextSizeException($message),
            $name === 'ContentFilterError' => new ContentFilterException($message),
            $status === 429 => new RateLimitExceededException(null, $message),
            $status !== null && $status >= 500 => new ServerException($status, $message),
            default => new RuntimeException(sprintf('The opencode server reported %s: %s', $name, $message)),
        };
    }

    /**
     * Map the server's finish wording onto the platform's cases.
     *
     * The server passes through what the AI SDK reports, which spells tool calls and content
     * filtering with a hyphen and a plural.
     *
     * @param string $finish
     * @return FinishReasonCase
     */
    private function toFinishCase(string $finish): FinishReasonCase
    {
        return match ($finish) {
            'stop' => FinishReasonCase::STOP,
            'length' => FinishReasonCase::LENGTH,
            'tool-calls', 'tool_calls' => FinishReasonCase::TOOL_CALL,
            'content-filter', 'content_filter' => FinishReasonCase::CONTENT_FILTER,
            default => FinishReasonCase::OTHER,
        };
    }
}

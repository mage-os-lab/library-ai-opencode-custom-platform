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
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
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

        $answer = $this->textOf(is_array($data['parts'] ?? null) ? $data['parts'] : []);

        // The server has no native tool calling for the caller's tools, so they were offered as a
        // text contract and come back inside it; see ToolProtocol.
        [$text, $toolCalls] = $this->extractToolCalls($answer, is_array($options['tools'] ?? null) ? $options['tools'] : []);

        $converted = ($options['stream'] ?? false)
            ? new StreamResult($this->toStream($text, $toolCalls))
            : $this->toResult($text, $toolCalls);

        $finish = $info['finish'] ?? null;
        if ($toolCalls !== []) {
            // What the turn actually is, whatever the server reported: the model stopped to wait
            // for a tool result. A caller driving a tool loop branches on this.
            $converted->getMetadata()->add(
                'finish_reason',
                new FinishReason(FinishReasonCase::TOOL_CALL, is_string($finish) && $finish !== '' ? $finish : 'tool-calls'),
            );
        } elseif (is_string($finish) && $finish !== '') {
            $converted->getMetadata()->add('finish_reason', new FinishReason($this->toFinishCase($finish), $finish));
        }

        return $converted;
    }

    /**
     * Pull the tool call blocks out of the answer, leaving the prose that surrounded them.
     *
     * A block whose JSON does not parse, or that names a tool never offered, is deliberately left in
     * the text. The alternative — dropping it, or failing the call — turns a model's formatting slip
     * into either a silent no-op or a dead conversation, where leaving it in at least shows an
     * administrator what the model tried to do.
     *
     * @param string $answer
     * @param array<mixed> $tools The tools that were offered on this request
     * @return array{0: string, 1: list<ToolCall>}
     */
    private function extractToolCalls(string $answer, array $tools): array
    {
        if (!str_contains($answer, ToolProtocol::TAG_OPEN)) {
            return [$answer, []];
        }

        $offered = ToolProtocol::describe($tools);
        $calls = [];
        $text = (string) preg_replace_callback(
            ToolProtocol::PATTERN,
            static function (array $matches) use (&$calls, $offered): string {
                $decoded = json_decode($matches[1], true);
                $name = is_array($decoded) && is_string($decoded['name'] ?? null) ? $decoded['name'] : '';
                if ($name === '' || ($offered !== [] && !isset($offered[$name]))) {
                    return $matches[0];
                }

                $arguments = $decoded['arguments'] ?? [];
                /** @var array<string,mixed> $arguments */
                $arguments = is_array($arguments) ? $arguments : [];

                $calls[] = new ToolCall(
                    // The server never saw a tool call, so there is no provider id to carry through;
                    // a caller pairing a result back to its call needs one all the same.
                    'call_' . bin2hex(random_bytes(8)),
                    $name,
                    $arguments,
                );

                return '';
            },
            $answer,
        );

        return [trim($text), $calls];
    }

    /**
     * The result shape matching what the turn contained.
     *
     * @param string $text
     * @param list<ToolCall> $toolCalls
     * @return ResultInterface
     */
    private function toResult(string $text, array $toolCalls): ResultInterface
    {
        if ($toolCalls === []) {
            return new TextResult($text);
        }

        // Both, when the model explained itself and then called: a caller that only reads tool
        // calls still gets them, and one that renders the text still has it.
        return $text !== ''
            ? new MultiPartResult([new TextResult($text), new ToolCallResult($toolCalls)])
            : new ToolCallResult($toolCalls);
    }

    /**
     * The same answer as a stream, for a caller that asked to stream.
     *
     * One chunk each, because the server answers only once the agent has finished: there is nothing
     * partial left to emit by the time this runs.
     *
     * @param string $text
     * @param list<ToolCall> $toolCalls
     * @return \Generator
     */
    private function toStream(string $text, array $toolCalls): \Generator
    {
        if ($text !== '') {
            yield new TextDelta($text);
        }
        if ($toolCalls !== []) {
            yield new ToolCallComplete($toolCalls);
        }
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

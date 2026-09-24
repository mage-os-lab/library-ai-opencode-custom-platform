<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform\Test\Unit;

use MageOS\AiOpenCodeCustomPlatform\Factory;
use MageOS\AiOpenCodeCustomPlatform\ToolProtocol;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tool calling, emulated in the prompt because the server's message endpoint has no parameter for
 * the *caller's* tools.
 *
 * This is what makes the provider usable by a consumer whose whole design is a tool loop —
 * `MagoAssistant_Mago` runs one per turn — and it is the half of the contract most likely to rot,
 * since the wording sent and the syntax parsed have to stay in step.
 */
final class ToolCallingTest extends TestCase
{
    private const SESSION_ID = 'ses_test0000000000000000000';

    /**
     * The tools a caller offers, in the shape the platform's contract normalizes them to.
     *
     * @var list<array<string,mixed>>
     */
    private const TOOLS = [[
        'type' => 'function',
        'function' => [
            'name' => 'get_order_count',
            'description' => 'Count orders in a period.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['period' => ['type' => 'string', 'enum' => ['today', 'week']]],
                'required' => ['period'],
            ],
        ],
    ]];

    /**
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    private array $requests = [];

    /**
     * @var array<string, mixed>
     */
    private array $promptAnswer = [];

    protected function setUp(): void
    {
        $this->requests = [];
        $this->promptAnswer = self::answer('Hello');
    }

    public function test_offered_tools_reach_the_model_as_a_named_schema_it_can_call(): void
    {
        $this->invoke(self::ask('How many orders today?'), ['tools' => self::TOOLS]);

        $system = $this->bodyOf(1)['system'] ?? '';
        self::assertIsString($system);
        self::assertStringContainsString('get_order_count', $system);
        self::assertStringContainsString('Count orders in a period.', $system);
        self::assertStringContainsString('"enum":["today","week"]', $system);
        self::assertStringContainsString(ToolProtocol::TAG_OPEN, $system);
    }

    /**
     * A caller that offers no tools must not be told about a syntax it cannot handle, or a model
     * will use it and the caller will render the raw block to a user.
     */
    public function test_a_request_without_tools_says_nothing_about_tool_calls(): void
    {
        $this->invoke(self::ask('Hi'));

        self::assertArrayNotHasKey('system', $this->bodyOf(1));
    }

    public function test_the_servers_own_tools_stay_off_even_when_the_caller_offers_its_own(): void
    {
        $this->invoke(self::ask('How many orders today?'), ['tools' => self::TOOLS]);

        self::assertSame([['permission' => '*', 'pattern' => '*', 'action' => 'deny']], $this->bodyOf(0)['permission'] ?? null);
        self::assertSame(['*' => false], $this->bodyOf(1)['tools'] ?? null);
    }

    public function test_a_tagged_block_comes_back_as_a_real_tool_call(): void
    {
        $this->promptAnswer = self::answer(
            ToolProtocol::TAG_OPEN . '{"name":"get_order_count","arguments":{"period":"today"}}' . ToolProtocol::TAG_CLOSE
        );

        $result = $this->invoke(self::ask('How many orders today?'), ['tools' => self::TOOLS])->getResult();

        self::assertInstanceOf(ToolCallResult::class, $result);
        $calls = $result->getContent();
        self::assertCount(1, $calls);
        self::assertSame('get_order_count', $calls[0]->getName());
        self::assertSame(['period' => 'today'], $calls[0]->getArguments());
        self::assertNotSame('', $calls[0]->getId(), 'A caller pairing a result back to its call needs an id.');
    }

    public function test_several_blocks_come_back_as_several_calls(): void
    {
        $this->promptAnswer = self::answer(
            ToolProtocol::TAG_OPEN . '{"name":"get_order_count","arguments":{"period":"today"}}' . ToolProtocol::TAG_CLOSE
            . "\n"
            . ToolProtocol::TAG_OPEN . '{"name":"get_order_count","arguments":{"period":"week"}}' . ToolProtocol::TAG_CLOSE
        );

        $result = $this->invoke(self::ask('Today and this week?'), ['tools' => self::TOOLS])->getResult();

        self::assertInstanceOf(ToolCallResult::class, $result);
        self::assertCount(2, $result->getContent());
        self::assertNotSame(
            $result->getContent()[0]->getId(),
            $result->getContent()[1]->getId(),
            'Two calls in one turn need distinct ids.'
        );
    }

    /**
     * A model that explains itself and then calls must lose neither half.
     */
    public function test_text_alongside_a_call_is_kept_as_well(): void
    {
        $this->promptAnswer = self::answer(
            'Let me check that for you.'
            . ToolProtocol::TAG_OPEN . '{"name":"get_order_count","arguments":{"period":"today"}}' . ToolProtocol::TAG_CLOSE
        );

        $result = $this->invoke(self::ask('How many orders today?'), ['tools' => self::TOOLS])->getResult();

        self::assertInstanceOf(MultiPartResult::class, $result);
        $parts = $result->getContent();
        self::assertInstanceOf(TextResult::class, $parts[0]);
        self::assertSame('Let me check that for you.', $parts[0]->getContent());
        self::assertInstanceOf(ToolCallResult::class, $parts[1]);
    }

    /**
     * The turn stopped so the caller could run something, whatever the server called it. A tool loop
     * branches on this.
     */
    public function test_a_turn_with_calls_reports_a_tool_call_finish_reason(): void
    {
        $this->promptAnswer = self::answer(
            ToolProtocol::TAG_OPEN . '{"name":"get_order_count","arguments":{"period":"today"}}' . ToolProtocol::TAG_CLOSE
        );

        $result = $this->invoke(self::ask('How many?'), ['tools' => self::TOOLS]);
        $result->getResult();

        $reason = $result->getMetadata()->get('finish_reason');
        self::assertInstanceOf(FinishReason::class, $reason);
        self::assertSame(FinishReasonCase::TOOL_CALL, $reason->getCase());
    }

    /**
     * Malformed output is a formatting slip, not a reason to lose the conversation. Left in the text
     * so an administrator can see what the model tried; dropping it silently would look like the
     * model said nothing.
     *
     * @param string $answer
     */
    #[\PHPUnit\Framework\Attributes\TestWith(['<tool_call>{"name":"get_order_count",}</tool_call>'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['<tool_call>not json at all</tool_call>'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['<tool_call>{"arguments":{"period":"today"}}</tool_call>'])]
    public function test_a_malformed_block_is_returned_as_text_rather_than_failing(string $answer): void
    {
        $this->promptAnswer = self::answer($answer);

        $result = $this->invoke(self::ask('How many?'), ['tools' => self::TOOLS])->getResult();

        self::assertInstanceOf(TextResult::class, $result);
        self::assertStringContainsString('tool_call', (string) $result->getContent());
    }

    /**
     * A model inventing a tool name would otherwise have the caller look up a tool it never offered.
     */
    public function test_a_call_naming_a_tool_that_was_not_offered_is_returned_as_text(): void
    {
        $this->promptAnswer = self::answer(
            ToolProtocol::TAG_OPEN . '{"name":"delete_everything","arguments":{}}' . ToolProtocol::TAG_CLOSE
        );

        $result = $this->invoke(self::ask('Clean up'), ['tools' => self::TOOLS])->getResult();

        self::assertInstanceOf(TextResult::class, $result);
        self::assertStringContainsString('delete_everything', (string) $result->getContent());
    }

    /**
     * The second turn of a tool loop: the caller replays its own earlier call and the result it got.
     * Each session is fresh, so both have to reach the model inside the prompt, and the call is
     * written in exactly the syntax the model was asked to produce.
     */
    public function test_an_earlier_call_and_its_result_are_replayed_into_the_prompt(): void
    {
        $this->invoke(
            new MessageBag(
                Message::ofUser('How many orders today?'),
                Message::ofAssistant('Checking.', new ToolCall('call_1', 'get_order_count', ['period' => 'today'])),
                Message::ofToolCall(new ToolCall('call_1', 'get_order_count', ['period' => 'today']), '{"count":42}'),
            ),
            ['tools' => self::TOOLS],
        );

        $prompt = $this->bodyOf(1)['parts'][0]['text'] ?? '';
        self::assertIsString($prompt);
        self::assertStringContainsString('User: How many orders today?', $prompt);
        self::assertStringContainsString(
            ToolProtocol::TAG_OPEN . '{"name":"get_order_count","arguments":{"period":"today"}}' . ToolProtocol::TAG_CLOSE,
            $prompt,
        );
        self::assertStringContainsString('{"count":42}', $prompt);
        self::assertStringContainsString('Tool result', $prompt);
    }

    public function test_a_streaming_caller_gets_the_calls_as_one_completed_delta(): void
    {
        $this->promptAnswer = self::answer(
            'Checking.'
            . ToolProtocol::TAG_OPEN . '{"name":"get_order_count","arguments":{"period":"today"}}' . ToolProtocol::TAG_CLOSE
        );

        $deltas = iterator_to_array(
            $this->invoke(self::ask('How many?'), ['tools' => self::TOOLS, 'stream' => true])->asStream(),
            false,
        );

        $texts = array_values(array_filter($deltas, static fn (mixed $d): bool => $d instanceof TextDelta));
        $calls = array_values(array_filter($deltas, static fn (mixed $d): bool => $d instanceof ToolCallComplete));
        self::assertSame('Checking.', $texts[0]->getText());
        self::assertCount(1, $calls);
        self::assertSame('get_order_count', $calls[0]->getToolCalls()[0]->getName());
    }

    /**
     * @param array<string,mixed> $options
     */
    private function invoke(MessageBag $messages, array $options = []): \Symfony\AI\Platform\Result\DeferredResult
    {
        return $this->platform()->invoke('anthropic/claude-haiku-4-5', $messages, $options);
    }

    private function platform(): Platform
    {
        return Factory::createPlatform('', $this->fakeServer());
    }

    private function fakeServer(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $json = ['response_headers' => ['content-type' => 'application/json']];

            if ($method === 'POST' && str_ends_with($url, '/session')) {
                return new MockResponse((string) json_encode(['id' => self::SESSION_ID]), $json);
            }
            if ($method === 'POST' && str_ends_with($url, '/message')) {
                return new MockResponse((string) json_encode($this->promptAnswer), $json);
            }

            return new MockResponse('true', $json);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(int $index): array
    {
        $body = json_decode((string) ($this->requests[$index]['options']['body'] ?? ''), true);

        return is_array($body) ? $body : [];
    }

    private static function ask(string $question): MessageBag
    {
        return new MessageBag(Message::ofUser($question));
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(string $text): array
    {
        return [
            'info' => ['role' => 'assistant', 'finish' => 'stop', 'tokens' => ['input' => 1, 'output' => 1, 'reasoning' => 0, 'cache' => ['read' => 0, 'write' => 0]]],
            'parts' => [['type' => 'text', 'text' => $text]],
        ];
    }
}

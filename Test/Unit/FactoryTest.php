<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform\Test\Unit;

use MageOS\AiOpenCodeCustomPlatform\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The whole round trip against a fake opencode server: which requests go out, in which order,
 * with which safety switches, and how the answer comes back.
 */
final class FactoryTest extends TestCase
{
    private const SESSION_ID = 'ses_test0000000000000000000';

    /**
     * Every request the fake server received.
     *
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    private array $requests = [];

    /**
     * What the fake server answers the prompt with.
     *
     * @var array<string, mixed>
     */
    private array $promptAnswer = [];

    /**
     * Status the fake server answers the prompt with.
     */
    private int $promptStatus = 200;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->promptStatus = 200;
        $this->promptAnswer = self::answer('Paris');
    }

    public function test_one_completion_is_one_session_created_prompted_and_deleted(): void
    {
        $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Capital of France?'))->asText();

        self::assertSame(
            [
                'POST http://127.0.0.1:4096/session',
                'POST http://127.0.0.1:4096/session/' . self::SESSION_ID . '/message',
                'DELETE http://127.0.0.1:4096/session/' . self::SESSION_ID,
            ],
            array_map(static fn (array $r): string => $r['method'] . ' ' . $r['url'], $this->requests),
        );
    }

    public function test_it_returns_the_text_the_model_wrote(): void
    {
        $this->promptAnswer = self::answer('Paris', [
            ['type' => 'reasoning', 'text' => 'The user asks about France.'],
            ['type' => 'text', 'text' => 'injected context', 'synthetic' => true],
        ]);

        self::assertSame(
            'Paris',
            $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Capital of France?'))->asText(),
        );
    }

    /**
     * The server is an agent that may otherwise read files and run commands on its host. Both
     * switches go out on every call, because the server ignores request fields it does not know:
     * one of them silently becoming a no-op after an upgrade must not be enough to lose both.
     */
    public function test_every_call_switches_tools_off_and_denies_every_permission(): void
    {
        $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();

        $session = $this->bodyOf(0);
        self::assertSame([['permission' => '*', 'pattern' => '*', 'action' => 'deny']], $session['permission'] ?? null);

        $prompt = $this->bodyOf(1);
        self::assertSame(['*' => false], $prompt['tools'] ?? null);
    }

    public function test_it_splits_the_model_into_the_provider_and_model_the_server_routes_by(): void
    {
        $this->platform()->invoke('openrouter/anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();

        self::assertSame(
            ['providerID' => 'openrouter', 'modelID' => 'anthropic/claude-sonnet-4-6'],
            $this->bodyOf(1)['model'] ?? null,
        );
    }

    public function test_system_messages_go_to_the_servers_own_system_field(): void
    {
        $this->platform()->invoke(
            'anthropic/claude-sonnet-4-6',
            new MessageBag(Message::forSystem('Answer in one word.'), Message::ofUser('Capital of France?')),
        )->asText();

        $prompt = $this->bodyOf(1);
        self::assertSame('Answer in one word.', $prompt['system'] ?? null);
        self::assertSame([['type' => 'text', 'text' => 'Capital of France?']], $prompt['parts'] ?? null);
    }

    /**
     * Each call gets a fresh session, so earlier turns have to travel inside the one prompt.
     */
    public function test_a_conversation_is_sent_as_one_labelled_transcript(): void
    {
        $this->platform()->invoke('anthropic/claude-sonnet-4-6', new MessageBag(
            Message::ofUser('Capital of France?'),
            Message::ofAssistant('Paris.'),
            Message::ofUser('And of Italy?'),
        ))->asText();

        self::assertSame(
            "User: Capital of France?\n\nAssistant: Paris.\n\nUser: And of Italy?",
            $this->bodyOf(1)['parts'][0]['text'] ?? null,
        );
    }

    public function test_it_names_the_configured_agent_and_otherwise_leaves_the_servers_default(): void
    {
        $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();
        self::assertArrayNotHasKey('agent', $this->bodyOf(1));

        $this->requests = [];
        $this->platform(agent: 'magento')->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();
        self::assertSame('magento', $this->bodyOf(1)['agent'] ?? null);
    }

    public function test_it_authenticates_with_basic_auth_when_a_password_is_set(): void
    {
        $this->platform(password: 's3cret', username: 'shop')
            ->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();

        foreach ($this->requests as $request) {
            self::assertContains(
                'Authorization: Basic ' . base64_encode('shop:s3cret'),
                $request['options']['headers'] ?? [],
            );
        }
    }

    public function test_it_sends_no_credentials_to_a_server_running_without_a_password(): void
    {
        $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();

        foreach ($this->requests as $request) {
            foreach ($request['options']['headers'] ?? [] as $header) {
                self::assertStringStartsNotWith('Authorization:', (string) $header);
            }
        }
    }

    public function test_it_honours_a_custom_base_url(): void
    {
        $this->platform(baseUrl: 'http://host.docker.internal:4096/')
            ->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();

        self::assertSame('http://host.docker.internal:4096/session', $this->requests[0]['url']);
    }

    /**
     * The server reports an upstream failure as HTTP 200 with the error on the message. Treating
     * that as an answer would hand a caller an empty string for a rejected API key.
     */
    public function test_an_upstream_error_on_the_message_is_raised_not_returned_as_empty_text(): void
    {
        $this->promptAnswer = ['info' => ['error' => [
            'name' => 'APIError',
            'data' => ['message' => 'Error from provider: invalid x-api-key', 'statusCode' => 401],
        ]], 'parts' => []];

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('invalid x-api-key');

        $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();
    }

    public function test_upstream_errors_map_onto_the_platforms_own_exceptions(): void
    {
        $this->promptAnswer = ['info' => ['error' => ['name' => 'ContextOverflowError', 'data' => ['message' => 'too long']]]];
        try {
            $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();
            self::fail('Expected an exception');
        } catch (ExceedContextSizeException) {
        }

        $this->promptAnswer = ['info' => ['error' => ['name' => 'APIError', 'data' => ['message' => 'slow down', 'statusCode' => 429]]]];
        $this->expectException(RateLimitExceededException::class);
        $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();
    }

    /**
     * A session left behind is a line in the operator's session list; losing the real error over
     * it would be worse. So the session is removed even when the prompt fails.
     */
    public function test_the_session_is_deleted_even_when_the_prompt_fails(): void
    {
        $this->promptStatus = 500;

        try {
            $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'))->asText();
            self::fail('Expected an exception');
        } catch (\Throwable) {
        }

        self::assertSame('DELETE', end($this->requests)['method']);
    }

    /**
     * Verified against a live 1.18.32 server: an unconfigured provider is answered with HTTP 500
     * and "Unexpected server error", naming nothing. The model is the likeliest culprit.
     */
    public function test_a_server_error_on_the_prompt_names_the_model(): void
    {
        $this->promptStatus = 500;
        $this->promptAnswer = ['name' => 'UnknownError', 'data' => ['message' => 'Unexpected server error.']];

        $this->expectException(\Symfony\AI\Platform\Exception\ServerException::class);
        $this->expectExceptionMessage('provider "nosuchprovider"');

        $this->platform()->invoke('nosuchprovider/x', self::ask('Hi'));
    }

    public function test_a_rejected_server_password_names_what_to_check(): void
    {
        $client = new MockHttpClient(static fn (): ResponseInterface => new MockResponse('Unauthorized', ['http_code' => 401]));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('OPENCODE_SERVER_PASSWORD');

        Factory::createPlatform('wrong', $client)->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'));
    }

    public function test_it_reports_the_token_counts_the_server_recorded(): void
    {
        $result = $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'));
        $result->asText();

        $usage = $result->getMetadata()->get('token_usage');
        self::assertInstanceOf(TokenUsageInterface::class, $usage);
        self::assertSame(12, $usage->getPromptTokens());
        self::assertSame(3, $usage->getCompletionTokens());
        self::assertSame(1, $usage->getThinkingTokens());
        self::assertSame(5, $usage->getCacheReadTokens());
        self::assertSame(2, $usage->getCacheCreationTokens());
    }

    public function test_it_reports_why_the_model_stopped(): void
    {
        $this->promptAnswer = self::answer('Paris', [], 'length');

        $result = $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'));
        $result->asText();

        $reason = $result->getMetadata()->get('finish_reason');
        self::assertInstanceOf(FinishReason::class, $reason);
        self::assertSame(FinishReasonCase::LENGTH, $reason->getCase());
    }

    /**
     * The server answers only once the agent is done, so a streaming caller gets the whole answer
     * as one delta rather than an error.
     */
    public function test_a_streaming_caller_gets_the_answer_as_a_single_delta(): void
    {
        $deltas = iterator_to_array(
            $this->platform()->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'), ['stream' => true])->asStream(),
            false,
        );

        $texts = array_values(array_filter($deltas, static fn (mixed $d): bool => $d instanceof TextDelta));
        self::assertCount(1, $texts);
        self::assertSame('Paris', $texts[0]->getText());
    }

    public function test_an_unreachable_server_is_named_in_the_error(): void
    {
        $client = new MockHttpClient(static fn (): ResponseInterface => new MockResponse('', ['error' => 'Connection refused']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http://127.0.0.1:4096');

        Factory::createPlatform('', $client)->invoke('anthropic/claude-sonnet-4-6', self::ask('Hi'));
    }

    /**
     * The contract callers dispatch through: `MageOS_AiBase` passes the credential positionally
     * and everything else by name.
     */
    public function test_it_takes_the_password_first_and_names_its_other_parameters(): void
    {
        $parameters = (new \ReflectionMethod(Factory::class, 'createPlatform'))->getParameters();

        self::assertSame('apiKey', $parameters[0]->getName());
        self::assertTrue($parameters[0]->isOptional(), 'A server without a password must not need one.');

        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters);
        foreach (['baseUrl', 'username', 'agent', 'modelCatalog'] as $name) {
            self::assertContains($name, $names);
        }
    }

    public function test_the_provider_reports_the_opencode_custom_name(): void
    {
        self::assertSame('opencode-custom', Factory::createProvider('', new MockHttpClient())->getName());
    }

    private function platform(
        string $password = '',
        string $username = 'opencode',
        string $baseUrl = 'http://127.0.0.1:4096',
        string $agent = '',
    ): \Symfony\AI\Platform\Platform {
        return Factory::createPlatform(
            $password,
            $this->fakeServer(),
            baseUrl: $baseUrl,
            username: $username,
            agent: $agent,
        );
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
                return new MockResponse((string) json_encode($this->promptAnswer), ['http_code' => $this->promptStatus] + $json);
            }
            if ($method === 'DELETE') {
                return new MockResponse('true', $json);
            }

            return new MockResponse('{"name":"NotFoundError","data":{"message":"nope"}}', ['http_code' => 404] + $json);
        });
    }

    /**
     * The JSON body of the n-th request the fake server received.
     *
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
     * An assistant message as the server answers it.
     *
     * @param list<array<string, mixed>> $extraParts
     * @return array<string, mixed>
     */
    private static function answer(string $text, array $extraParts = [], string $finish = 'stop'): array
    {
        return [
            'info' => [
                'role' => 'assistant',
                'finish' => $finish,
                'tokens' => ['input' => 12, 'output' => 3, 'reasoning' => 1, 'cache' => ['read' => 5, 'write' => 2]],
            ],
            'parts' => array_merge(
                [['type' => 'step-start']],
                $extraParts,
                [['type' => 'text', 'text' => $text]],
                [['type' => 'step-finish', 'reason' => $finish]],
            ),
        ];
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform;

use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Turns one stateless completion into one short-lived opencode session.
 *
 * An opencode server is not a completion endpoint. It is an agent: every exchange lives in a
 * session, every prompt runs the agent loop, and by default that loop may read files, edit them
 * and run shell commands on the machine the server runs on. So each call here:
 *
 * 1. creates a session whose permission ruleset denies everything,
 * 2. prompts it once with every tool switched off,
 * 3. deletes the session again, whatever happened in between.
 *
 * Two independent switches rather than one, because the server silently ignores request fields it
 * does not recognise: a renamed option in a future release would turn one of them into a no-op
 * without an error, and the other still holds.
 *
 * The server answers the prompt only once the agent has finished, so there is nothing to stream;
 * {@see ResultConverter} hands a streaming caller the whole answer as one chunk.
 */
class ModelClient implements ModelClientInterface
{
    /**
     * The permission ruleset every session is created with: any permission, any pattern, denied.
     */
    private const DENY_EVERYTHING = [['permission' => '*', 'pattern' => '*', 'action' => 'deny']];

    /**
     * The tools map sent with every prompt: every tool, off.
     */
    private const NO_TOOLS = ['*' => false];

    /**
     * Title given to each session, so one left behind by a crashed PHP process is recognisable in
     * the server's session list rather than looking like the operator's own work.
     */
    private const SESSION_TITLE = 'Mage-OS AI request';

    /**
     * Seconds to wait for the server's answer.
     *
     * Long because the server sends nothing until the agent has finished, and a large model behind
     * a slow provider can take minutes. Symfony's own default (the `default_socket_timeout`, often
     * 60 s) would abort a request the server was about to answer.
     */
    public const DEFAULT_TIMEOUT = 300.0;

    /**
     * @param HttpClientInterface $httpClient
     * @param string $baseUrl Server address, e.g. http://127.0.0.1:4096
     * @param string $password `OPENCODE_SERVER_PASSWORD`; empty when the server runs without one
     * @param string $username `OPENCODE_SERVER_USERNAME`; the server's own default is `opencode`
     * @param string $agent Agent to prompt; empty for the server's default agent
     * @param float $timeout Seconds to wait for an answer
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $password = '',
        private readonly string $username = Factory::DEFAULT_USERNAME,
        private readonly string $agent = '',
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
    ) {
    }

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
     * @param array<string,mixed>|string $payload
     * @param array<string,mixed> $options
     */
    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (!$model instanceof OpenCodeServerModel) {
            throw new InvalidArgumentException(sprintf('"%s" only handles %s.', self::class, OpenCodeServerModel::class));
        }
        if (!empty($options['tools'])) {
            throw new InvalidArgumentException(
                'Tool calling is not available through an opencode server: its agent runs tools on '
                . 'the server itself, and this bridge switches them off.'
            );
        }

        $body = $this->toPromptBody($model, $payload);

        $sessionId = $this->createSession();
        try {
            $response = $this->send(
                'POST',
                '/session/' . rawurlencode($sessionId) . '/message',
                $body,
                // Verified against 1.18.32: a provider the server has not configured is answered
                // with a bare "Unexpected server error", which names neither the model nor why.
                sprintf(
                    'Model "%s": check that the server has provider "%s" configured and serves this model.',
                    $model->getName(),
                    $model->getProviderId(),
                ),
            );

            return new InMemoryRawResult($this->decode($response), [], $response);
        } finally {
            $this->deleteSession($sessionId);
        }
    }

    /**
     * Build the prompt body from the platform's chat payload.
     *
     * A session is fresh for every call, so the conversation history has to travel inside the one
     * prompt. System messages go to the server's own `system` field; everything else becomes a
     * single text part. A lone user message is sent as-is; a longer exchange is laid out as a
     * labelled transcript ending in the latest turn, which every chat model reads correctly.
     *
     * @param OpenCodeServerModel $model
     * @param array<string,mixed>|string $payload
     * @return array<string,mixed>
     */
    private function toPromptBody(OpenCodeServerModel $model, array|string $payload): array
    {
        $messages = is_array($payload) ? ($payload['messages'] ?? null) : null;
        if (!is_array($messages) || $messages === []) {
            throw new InvalidArgumentException(sprintf(
                '"%s" expects a message bag; received %s.',
                self::class,
                is_string($payload) ? 'a bare string' : 'no messages',
            ));
        }

        $system = [];
        $turns = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = is_string($message['role'] ?? null) ? $message['role'] : '';
            if ($role === 'tool' || !empty($message['tool_calls'])) {
                throw new InvalidArgumentException(
                    'Tool call messages cannot be sent to an opencode server; this bridge offers no tools.'
                );
            }

            $text = $this->textOf($message['content'] ?? '');
            if ($role === 'system') {
                $system[] = $text;
                continue;
            }
            $turns[] = ['role' => $role, 'text' => $text];
        }

        if ($turns === []) {
            throw new InvalidArgumentException('An opencode server needs at least one user message to answer.');
        }

        $body = [
            'model' => ['providerID' => $model->getProviderId(), 'modelID' => $model->getModelId()],
            'tools' => self::NO_TOOLS,
            'parts' => [['type' => 'text', 'text' => $this->flatten($turns)]],
        ];
        if ($system !== []) {
            $body['system'] = implode("\n\n", $system);
        }
        if ($this->agent !== '') {
            $body['agent'] = $this->agent;
        }

        return $body;
    }

    /**
     * The text of one message's content, refusing anything that is not text.
     *
     * Images and documents are refused rather than dropped: a caller who attached one expects it to
     * be read, and silently answering without it would look like the model ignored it.
     *
     * @param mixed $content
     * @return string
     */
    private function textOf(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $texts = [];
        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                $texts[] = $item['text'];
                continue;
            }
            throw new InvalidArgumentException('Only text content can be sent to an opencode server.');
        }

        return implode("\n", $texts);
    }

    /**
     * One text part out of the conversation turns.
     *
     * @param list<array{role:string,text:string}> $turns
     * @return string
     */
    private function flatten(array $turns): string
    {
        if (count($turns) === 1) {
            return $turns[0]['text'];
        }

        $lines = [];
        foreach ($turns as $turn) {
            $label = $turn['role'] === 'assistant' ? 'Assistant' : 'User';
            $lines[] = $label . ': ' . $turn['text'];
        }

        return implode("\n\n", $lines);
    }

    /**
     * Open a session that may do nothing but talk.
     *
     * @return string Session id
     */
    private function createSession(): string
    {
        $session = $this->decode($this->send('POST', '/session', [
            'title' => self::SESSION_TITLE,
            'permission' => self::DENY_EVERYTHING,
        ]));

        $id = $session['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new RuntimeException(sprintf('The opencode server at %s created no session.', $this->baseUrl));
        }

        return $id;
    }

    /**
     * Remove the session again, never letting that failure replace the answer or the real error.
     *
     * Best effort by design: the prompt has been answered or has failed on its own terms by now,
     * and a session left behind is a line in the server's session list, titled so an operator
     * recognises it. Losing the answer over it would be the worse outcome.
     *
     * @param string $sessionId
     */
    private function deleteSession(string $sessionId): void
    {
        try {
            $this->send('DELETE', '/session/' . rawurlencode($sessionId))->getStatusCode();
        } catch (\Throwable) {
            // See the docblock: a stale session is preferable to a lost answer.
        }
    }

    /**
     * Send one request and fail with a platform exception on a non-2xx answer.
     *
     * @param string $method
     * @param string $path
     * @param array<string,mixed>|null $json
     * @param string|null $serverErrorHint Appended to a 5xx error, where the server says least
     * @return ResponseInterface
     */
    private function send(
        string $method,
        string $path,
        ?array $json = null,
        ?string $serverErrorHint = null,
    ): ResponseInterface {
        $options = ['timeout' => $this->timeout, 'headers' => ['Accept' => 'application/json']];
        if ($json !== null) {
            $options['json'] = $json;
        }
        if ($this->password !== '') {
            $options['auth_basic'] = [$this->username, $this->password];
        }

        $url = rtrim($this->baseUrl, '/') . $path;
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
        } catch (HttpClientException $e) {
            throw new RuntimeException(
                sprintf('Could not reach the opencode server at %s: %s', $this->baseUrl, $e->getMessage()),
                0,
                $e,
            );
        }

        if ($status >= 200 && $status < 300) {
            return $response;
        }

        $message = $this->errorMessageOf($response);

        throw match (true) {
            $status === 401, $status === 403 => new AuthenticationException(sprintf(
                'The opencode server at %s rejected the credentials (HTTP %d). Check the username and '
                . 'the server password (OPENCODE_SERVER_PASSWORD).',
                $this->baseUrl,
                $status,
            )),
            $status === 429 => new RateLimitExceededException(null, $message),
            $status >= 500 => new ServerException(
                $status,
                trim(($message ?? '') . ' ' . ($serverErrorHint ?? '')) ?: null,
            ),
            default => new RuntimeException(sprintf(
                'The opencode server at %s answered %s %s with HTTP %d%s',
                $this->baseUrl,
                $method,
                $path,
                $status,
                $message !== null ? ': ' . $message : '.',
            )),
        };
    }

    /**
     * The message out of the server's error body, which is `{name, data: {message}}`.
     *
     * @param ResponseInterface $response
     * @return string|null
     */
    private function errorMessageOf(ResponseInterface $response): ?string
    {
        try {
            $body = json_decode($response->getContent(false), true);
        } catch (\Throwable) {
            return null;
        }

        $message = is_array($body) && is_array($body['data'] ?? null) ? ($body['data']['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * Decode a JSON object body.
     *
     * @param ResponseInterface $response
     * @return array<string,mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            $data = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException | HttpClientException $e) {
            throw new RuntimeException(
                sprintf('The opencode server at %s did not answer with JSON.', $this->baseUrl),
                0,
                $e,
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('The opencode server at %s did not answer with an object.', $this->baseUrl));
        }

        /** @var array<string,mixed> $data */
        return $data;
    }
}

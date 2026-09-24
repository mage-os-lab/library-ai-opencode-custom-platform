# mage-os/library-ai-opencode-custom-platform

A [Symfony AI](https://github.com/symfony/ai) platform bridge for a self-hosted
[OpenCode](https://opencode.ai/) server (`opencode serve`).

It lets anything that accepts a `Symfony\AI\Platform\PlatformInterface` use your own opencode server
as a model gateway, including the `opencode-custom` provider of
[`mage-os/module-ai-base`](https://github.com/mage-os-lab/module-ai-base). Through one server you
reach every provider it has configured (Anthropic, OpenAI, a local Ollama, …), with the provider
credentials staying on the server.

This is a plain PHP library. It contains no Magento code.

For the hosted OpenCode Zen gateway, use `mage-os/library-ai-opencode-zen-platform` instead.

## Installation

```bash
composer require mage-os/library-ai-opencode-custom-platform
```

## Usage

```php
use MageOS\AiOpenCodeCustomPlatform\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

$platform = Factory::createPlatform(
    'server-password',                 // OPENCODE_SERVER_PASSWORD; '' if the server has none
    baseUrl: 'http://127.0.0.1:4096',
    username: 'opencode',              // OPENCODE_SERVER_USERNAME; the server's default is 'opencode'
    agent: 'magento',                  // optional; '' uses the server's default agent
);

echo $platform->invoke(
    'anthropic/claude-sonnet-4-6',
    new MessageBag(Message::forSystem('Answer briefly.'), Message::ofUser('Why is the sky blue?')),
)->asText();
```

Models are addressed as **`providerID/modelID`**, exactly as the server routes them. Everything
after the first `/` is the model id, so `openrouter/anthropic/claude-sonnet-4-6` works. List what
your server offers with `GET /config/providers`.

## Running the server

```bash
OPENCODE_SERVER_PASSWORD=choose-one opencode serve --port 4096
```

`opencode serve` listens on `127.0.0.1` by default. **If the PHP application runs in a container**,
`127.0.0.1` there is the container itself. Start the server on all interfaces, and address it through
the host gateway:

```bash
OPENCODE_SERVER_PASSWORD=choose-one opencode serve --hostname 0.0.0.0 --port 4096
```

```
baseUrl: http://host.docker.internal:4096
```

(with `extra_hosts: ["host.docker.internal:host-gateway"]` on the container on Linux). A server
listening on `0.0.0.0` without a password is an unauthenticated remote-control API for an agent on
that machine. Always set one.

## How a call works

The server is not a completion endpoint but an agent, and by default that agent may read, edit and
run commands on the machine it runs on. So each call:

1. creates a session whose permission ruleset denies everything (`[{permission: "*", pattern: "*", action: "deny"}]`),
2. prompts it once with every tool switched off (`tools: {"*": false}`),
3. deletes the session again, whether the prompt succeeded or not.

Two switches rather than one, because the server silently ignores request fields it does not
recognise. If a future release renames one of them, it becomes a no-op without an error, and the
other one still holds.

> **Not verified against a live model.** The unit tests assert that both switches go out on every
> request. That a real model behind a real server then cannot reach a tool has not been
> demonstrated. Treat the server's host as reachable by whoever can send it prompts, and run it as
> an unprivileged user.

Each call gets a fresh session, so the conversation history travels inside the one prompt: system
messages go to the server's `system` field, and the rest is sent as a labelled transcript (`User:` /
`Assistant:`) ending in the latest turn.

## The agent

Answers come from an opencode *agent*. The server's default carries opencode's coding-assistant
system prompt and reads the `AGENTS.md` of the directory the server was started in. To keep both
out of your answers, define a plain agent in the server's `opencode.json` and pass its name as
`agent`:

```json
{
  "agent": {
    "magento": {
      "description": "Plain completions for Magento",
      "mode": "primary",
      "prompt": "You are a helpful assistant.",
      "tools": { "*": false }
    }
  }
}
```

## Limits

| Feature | Status |
|---|---|
| Text in, text out | Yes |
| System prompt | Yes, via the server's `system` field |
| Multi-turn history | Yes, flattened into one prompt |
| Token usage | Yes, from the server's own per-message count (`info.tokens`) |
| Finish reason | Yes, when the server reports one |
| Streaming | Answered in one chunk: the server replies only once the agent has finished |
| Tool calling | **No**: refused before any request is made |
| Images / files | **No**: refused rather than silently dropped |
| `max_tokens`, `temperature`, `top_p`, `stop` | **No**: the server's message endpoint has no such fields |

The request can take minutes, because the server replies only once the agent has finished. The
client waits up to `ModelClient::DEFAULT_TIMEOUT` (300 s).

## Errors

The server reports an upstream failure as **HTTP 200** with the error on the message
(`info.error`). This bridge raises that error as the matching platform exception
(`AuthenticationException`, `RateLimitExceededException`, `ExceedContextSizeException`, …), so a
rejected upstream API key is never returned as an empty answer. A wrong server password is an
`AuthenticationException` naming `OPENCODE_SERVER_PASSWORD`.

For an unconfigured provider, a 1.18.32 server answers only "Unexpected server error"; the bridge
adds the model and provider to that message.

## Versioning

The session API was verified against **opencode 1.18.32** via its OpenAPI spec (`GET /doc`).
`symfony/ai-platform` is experimental and makes no backward-compatibility promise; this package is
verified against **v0.13.0** and constrains itself to `^0.13`.

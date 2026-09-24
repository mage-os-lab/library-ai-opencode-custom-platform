<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform;

use Symfony\AI\Platform\Model;

/**
 * A model addressed through an opencode server, named `providerID/modelID`.
 *
 * A dedicated class rather than the platform's plain Model so that only this bridge's client and
 * converter claim it: a platform can hold several providers, and each one picks its models by
 * class.
 *
 * The server does not have one model namespace of its own. It routes to whichever providers it
 * has configured — Anthropic, OpenAI, a local Ollama — and needs both halves of the name to do it,
 * which is why the name is split rather than passed through.
 */
class OpenCodeServerModel extends Model
{
    /**
     * The server-side provider id, e.g. `anthropic`: everything before the first slash.
     *
     * @return string
     */
    public function getProviderId(): string
    {
        return explode('/', $this->getName(), 2)[0];
    }

    /**
     * The model id within that provider: everything after the first slash.
     *
     * Only the first slash separates, because model ids contain their own; a provider such as
     * OpenRouter names its models `anthropic/claude-sonnet-4-6`, so the full name there is
     * `openrouter/anthropic/claude-sonnet-4-6`.
     *
     * @return string
     */
    public function getModelId(): string
    {
        return explode('/', $this->getName(), 2)[1] ?? '';
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

/**
 * Accepts any `providerID/modelID` the server might route, and nothing that is not shaped like one.
 *
 * Which models exist is a property of one particular server's configuration, not of this package:
 * two servers running the same opencode release can expose entirely different providers. So there
 * is nothing to enumerate, {@see getModels()} stays empty, and the server answers for its own
 * catalogue (`GET /config/providers`).
 *
 * The one thing checked locally is the shape. A name without a provider half cannot be routed at
 * all, and refusing it here says what is wrong in terms the administrator typed, instead of the
 * server's error about a provider it has never heard of.
 */
class ModelCatalog extends AbstractModelCatalog
{
    /**
     * What a model reached through the server can do, as far as this bridge can deliver it.
     *
     * Narrower than what the upstream model supports: structured output and attachments belong to
     * the server's agent loop, and this bridge does not surface them, so declaring them would let a
     * caller ask for something refused one layer down.
     *
     * Tool calling *is* declared, even though the server's message endpoint has no parameter for the
     * caller's tools: {@see ToolProtocol} emulates it in the prompt and {@see ResultConverter} parses
     * the calls back out. A caller therefore really does get tool calls, which is what the capability
     * claims — how reliably is a property of the model, as it is on every provider.
     */
    private const CAPABILITIES = [
        Capability::INPUT_MESSAGES,
        Capability::INPUT_TEXT,
        Capability::OUTPUT_TEXT,
        Capability::OUTPUT_STREAMING,
        Capability::TOOL_CALLING,
    ];

    /**
     * Declare the model map empty; this catalogue answers by shape rather than by lookup.
     */
    public function __construct()
    {
        $this->models = [];
    }

    /**
     * @inheritdoc
     */
    public function getModel(string $modelName): Model
    {
        $parsed = $this->parseModelName($modelName);
        $name = trim($parsed['name']);

        [$providerId, $modelId] = array_pad(explode('/', $name, 2), 2, '');
        if ($name === '' || $providerId === '' || trim($modelId) === '') {
            throw new InvalidArgumentException(sprintf(
                'Model "%s" is not addressed as "providerID/modelID". An opencode server routes to '
                . 'the providers it has configured, so the model needs both, e.g. '
                . '"anthropic/claude-sonnet-4-6".',
                $modelName,
            ));
        }

        return new OpenCodeServerModel($name, self::CAPABILITIES, $parsed['options']);
    }
}

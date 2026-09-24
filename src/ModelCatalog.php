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
     * Deliberately narrower than what the upstream model supports: tool calls, structured output
     * and attachments are all things the server's agent loop owns, and this bridge switches them
     * off rather than surface them. Declaring them would let a caller ask for a feature that is
     * then refused one layer down.
     */
    private const CAPABILITIES = [
        Capability::INPUT_MESSAGES,
        Capability::INPUT_TEXT,
        Capability::OUTPUT_TEXT,
        Capability::OUTPUT_STREAMING,
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

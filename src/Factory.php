<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform;

use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Symfony AI platform bridge for a self-hosted opencode server (`opencode serve`).
 *
 * Not an OpenAI-compatible endpoint: the server exposes opencode's own session API, and answers
 * through its agent, which routes to whatever providers the server has configured. See
 * {@see ModelClient} for how a completion is mapped onto that, and the README for the limits.
 *
 * The credential comes first, like every hosted Symfony bridge, because callers that dispatch over
 * a registry of bridge factories — `MageOS_AiBase` among them — pass it positionally. Here it is
 * the server's password (`OPENCODE_SERVER_PASSWORD`), used for HTTP basic auth, and it may be empty
 * for a server started without one.
 */
class Factory
{
    /**
     * Where `opencode serve` listens unless told otherwise.
     *
     * Plain HTTP and loopback: that is the server's own default. From inside a container,
     * 127.0.0.1 is the container itself, not the host; see the README.
     */
    public const DEFAULT_BASE_URL = 'http://127.0.0.1:4096';

    /**
     * The basic auth username the server expects unless `OPENCODE_SERVER_USERNAME` overrides it.
     */
    public const DEFAULT_USERNAME = 'opencode';

    /**
     * Provider name reported to the platform, and the key a model router resolves against.
     */
    public const PROVIDER_NAME = 'opencode-custom';

    /**
     * Build the opencode server provider.
     *
     * @param string $apiKey Server password; empty when the server runs without one
     * @param HttpClientInterface|null $httpClient
     * @param ModelCatalogInterface $modelCatalog
     * @param Contract|null $contract
     * @param EventDispatcherInterface|null $eventDispatcher
     * @param non-empty-string $name
     * @param string $baseUrl Server address
     * @param string $username Basic auth username
     * @param string $agent Agent to prompt; empty for the server's default agent
     * @return ProviderInterface
     */
    public static function createProvider(
        #[\SensitiveParameter] string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $name = self::PROVIDER_NAME,
        string $baseUrl = self::DEFAULT_BASE_URL,
        string $username = self::DEFAULT_USERNAME,
        string $agent = '',
    ): ProviderInterface {
        return new Provider(
            $name,
            [
                new ModelClient(
                    $httpClient ?? HttpClient::create(),
                    $baseUrl,
                    $apiKey,
                    $username !== '' ? $username : self::DEFAULT_USERNAME,
                    $agent,
                ),
            ],
            [new ResultConverter()],
            $modelCatalog,
            $contract,
            $eventDispatcher,
        );
    }

    /**
     * Build a platform holding nothing but the opencode server provider.
     *
     * @param string $apiKey Server password; empty when the server runs without one
     * @param HttpClientInterface|null $httpClient
     * @param ModelCatalogInterface $modelCatalog
     * @param Contract|null $contract
     * @param EventDispatcherInterface|null $eventDispatcher
     * @param non-empty-string $name
     * @param ModelRouterInterface|null $modelRouter
     * @param string $baseUrl Server address
     * @param string $username Basic auth username
     * @param string $agent Agent to prompt; empty for the server's default agent
     * @return Platform
     */
    public static function createPlatform(
        #[\SensitiveParameter] string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $name = self::PROVIDER_NAME,
        ?ModelRouterInterface $modelRouter = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        string $username = self::DEFAULT_USERNAME,
        string $agent = '',
    ): Platform {
        return new Platform(
            [
                self::createProvider(
                    $apiKey,
                    $httpClient,
                    $modelCatalog,
                    $contract,
                    $eventDispatcher,
                    $name,
                    $baseUrl,
                    $username,
                    $agent,
                ),
            ],
            $modelRouter ?? new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }
}

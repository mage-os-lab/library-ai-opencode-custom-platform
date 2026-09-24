<?php

declare(strict_types=1);

namespace MageOS\AiOpenCodeCustomPlatform\Test\Unit;

use MageOS\AiOpenCodeCustomPlatform\ModelCatalog;
use MageOS\AiOpenCodeCustomPlatform\OpenCodeServerModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\InvalidArgumentException;

final class ModelCatalogTest extends TestCase
{
    #[DataProvider('model_names')]
    public function test_it_splits_a_model_name_on_its_first_slash(string $name, string $provider, string $model): void
    {
        $resolved = (new ModelCatalog())->getModel($name);

        self::assertInstanceOf(OpenCodeServerModel::class, $resolved);
        self::assertSame($name, $resolved->getName());
        self::assertSame($provider, $resolved->getProviderId());
        self::assertSame($model, $resolved->getModelId());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function model_names(): array
    {
        return [
            'hosted provider'             => ['anthropic/claude-sonnet-4-6', 'anthropic', 'claude-sonnet-4-6'],
            'model id with its own slash' => ['openrouter/anthropic/claude-sonnet-4-6', 'openrouter', 'anthropic/claude-sonnet-4-6'],
            'local runtime with a tag'    => ['yireo-test-1/qwen3.5:9b', 'yireo-test-1', 'qwen3.5:9b'],
        ];
    }

    /**
     * Without a provider half the server cannot route the model at all; saying so here names what
     * the administrator typed rather than the server's complaint about an unknown provider.
     */
    #[DataProvider('unroutable_names')]
    public function test_it_refuses_a_name_the_server_cannot_route(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('providerID/modelID');

        (new ModelCatalog())->getModel($name);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unroutable_names(): array
    {
        return [
            'no provider'      => ['claude-sonnet-4-6'],
            'empty model half' => ['anthropic/'],
            'empty provider'   => ['/claude-sonnet-4-6'],
        ];
    }

    /**
     * Tool calling is emulated in the prompt rather than native, but a caller does get real tool
     * calls back, so the capability is claimed. Without it, a caller that checks before offering
     * tools would never offer any.
     */
    public function test_it_claims_tool_calling(): void
    {
        self::assertTrue((new ModelCatalog())->getModel('anthropic/claude-sonnet-4-6')->supports(Capability::TOOL_CALLING));
    }

    public function test_it_enumerates_nothing(): void
    {
        self::assertSame([], (new ModelCatalog())->getModels());
    }
}

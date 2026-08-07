<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service\Ai;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final class AiSettingsWriter
{
    /**
     * @var list<string>
     */
    private const PROVIDERS = [
        'openai',
        'anthropic',
        'grok',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly AiSettingsRepository $repository
    ) {
    }

    /**
     * Blank credentials preserve the currently stored encrypted value.
     *
     * @param array<string, mixed> $credentials
     *
     * @return array{
     *     active_provider: string|null,
     *     active_model: string|null,
     *     configured: array{
     *         openai: bool,
     *         anthropic: bool,
     *         grok: bool
     *     }
     * }
     */
    public function saveConfiguration(
        string $activeProvider,
        string $activeModel,
        array $credentials
    ): array {
        $activeProvider = strtolower(trim($activeProvider));
        $activeModel = trim($activeModel);

        if (!in_array($activeProvider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException(
                'Unsupported AI provider.'
            );
        }

        if ('' === $activeModel) {
            throw new InvalidArgumentException(
                'An AI model must be selected.'
            );
        }

        if (mb_strlen($activeModel) > 191) {
            throw new InvalidArgumentException(
                'The selected AI model is too long.'
            );
        }

        $this->connection->transactional(
            function () use (
                $activeProvider,
                $activeModel,
                $credentials
            ): void {
                $this->repository->getRawForUpdate();

                $changes = [
                    'active_provider' => $activeProvider,
                    'active_model' => $activeModel,
                ];

                foreach (self::PROVIDERS as $provider) {
                    if (!array_key_exists($provider, $credentials)) {
                        continue;
                    }

                    $credential = trim(
                        (string) ($credentials[$provider] ?? '')
                    );

                    if ('' === $credential) {
                        continue;
                    }

                    $changes[
                        $this->repository->credentialColumn($provider)
                    ] = $this->repository->encryptCredential(
                        $credential
                    );
                }

                $this->repository->updateRaw($changes);
            }
        );

        return $this->repository->getPublicConfiguration();
    }
}

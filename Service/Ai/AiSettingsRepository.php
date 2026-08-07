<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service\Ai;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Mautic\CoreBundle\Helper\EncryptionHelper;

final class AiSettingsRepository
{
    private const TABLE = 'zender_ai_settings';

    /**
     * @var array<string, string>
     */
    private const CREDENTIAL_COLUMNS = [
        'openai' => 'openai_api_key_encrypted',
        'anthropic' => 'anthropic_api_key_encrypted',
        'grok' => 'grok_api_key_encrypted',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly EncryptionHelper $encryptionHelper
    ) {
    }

    /**
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
    public function getPublicConfiguration(): array
    {
        $row = $this->fetchRow();

        return [
            'active_provider' => $this->normalizeNullableString(
                $row['active_provider'] ?? null
            ),
            'active_model' => $this->normalizeNullableString(
                $row['active_model'] ?? null
            ),
            'configured' => [
                'openai' => $this->hasEncryptedValue(
                    $row['openai_api_key_encrypted'] ?? null
                ),
                'anthropic' => $this->hasEncryptedValue(
                    $row['anthropic_api_key_encrypted'] ?? null
                ),
                'grok' => $this->hasEncryptedValue(
                    $row['grok_api_key_encrypted'] ?? null
                ),
            ],
        ];
    }

    public function getCredential(string $provider): ?string
    {
        $column = $this->credentialColumn($provider);
        $row = $this->fetchRow();
        $encrypted = $row[$column] ?? null;

        if (!$this->hasEncryptedValue($encrypted)) {
            return null;
        }

        $decrypted = $this->encryptionHelper->decrypt(
            (string) $encrypted
        );
        $value = trim((string) $decrypted);

        return '' === $value ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawForUpdate(): array
    {
        $table = MAUTIC_TABLE_PREFIX.self::TABLE;
        $row = $this->connection->fetchAssociative(
            'SELECT *
             FROM '.$table.'
             WHERE id = 1
             FOR UPDATE'
        );

        if (is_array($row) && [] !== $row) {
            return $row;
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->connection->insert(
            $table,
            [
                'id' => 1,
                'active_provider' => null,
                'active_model' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->connection->fetchAssociative(
            'SELECT *
             FROM '.$table.'
             WHERE id = 1
             FOR UPDATE'
        ) ?: [];
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function updateRaw(array $changes): void
    {
        if ([] === $changes) {
            return;
        }

        $allowed = [
            'active_provider',
            'active_model',
            'openai_api_key_encrypted',
            'anthropic_api_key_encrypted',
            'grok_api_key_encrypted',
        ];

        $payload = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $changes)) {
                $payload[$field] = $changes[$field];
            }
        }

        if ([] === $payload) {
            return;
        }

        $payload['updated_at'] = gmdate('Y-m-d H:i:s');

        $this->connection->update(
            MAUTIC_TABLE_PREFIX.self::TABLE,
            $payload,
            ['id' => 1]
        );
    }

    public function encryptCredential(string $credential): string
    {
        $credential = trim($credential);

        if ('' === $credential) {
            throw new InvalidArgumentException(
                'AI provider credential cannot be blank.'
            );
        }

        return (string) $this->encryptionHelper->encrypt(
            $credential
        );
    }

    public function credentialColumn(string $provider): string
    {
        $provider = strtolower(trim($provider));

        if (!isset(self::CREDENTIAL_COLUMNS[$provider])) {
            throw new InvalidArgumentException(
                'Unsupported AI provider.'
            );
        }

        return self::CREDENTIAL_COLUMNS[$provider];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT *
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE id = 1'
        );

        return is_array($row) ? $row : [];
    }

    private function hasEncryptedValue(mixed $value): bool
    {
        return null !== $value
            && '' !== trim((string) $value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return '' === $normalized ? null : $normalized;
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final class WhatsAppMessageRepository
{
    private const TABLE = 'zender_whatsapp_messages';

    public function __construct(
        private Connection $connection
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id,
                    name,
                    content,
                    asset_id,
                    delivery_destination,
                    is_published,
                    created_at,
                    updated_at
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             ORDER BY is_published DESC,
                      name ASC,
                      id ASC
             LIMIT 500'
        );

        return array_map(
            fn (array $row): array => $this->normalizeRow($row),
            $rows
        );
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    public function findPublishedChoices(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE is_published = 1
             ORDER BY name ASC, id ASC
             LIMIT 500'
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ],
            $rows
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id,
                    name,
                    content,
                    asset_id,
                    delivery_destination,
                    is_published,
                    created_at,
                    updated_at
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE id = :id',
            ['id' => $id]
        );

        return false === $row ? null : $this->normalizeRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublished(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id,
                    name,
                    content,
                    asset_id,
                    delivery_destination,
                    is_published,
                    created_at,
                    updated_at
             FROM '.MAUTIC_TABLE_PREFIX.self::TABLE.'
             WHERE id = :id
               AND is_published = 1',
            ['id' => $id]
        );

        return false === $row ? null : $this->normalizeRow($row);
    }

    public function save(
        ?int $id,
        string $name,
        string $content,
        bool $isPublished,
        ?int $assetId = null,
        string $deliveryDestination = 'campaign'
    ): int {
        $name = trim($name);
        $content = trim($content);
        $deliveryDestination = trim($deliveryDestination);

        if ('' === $name || mb_strlen($name) > 191) {
            throw new InvalidArgumentException(
                'WhatsApp message name must contain 1 to 191 characters.'
            );
        }

        if ('' === $content || mb_strlen($content) > 65535) {
            throw new InvalidArgumentException(
                'WhatsApp message content must contain 1 to 65535 characters.'
            );
        }

        if (null !== $assetId && $assetId < 1) {
            throw new InvalidArgumentException(
                'WhatsApp message asset ID must be null or a positive integer.'
            );
        }

        if (!in_array(
            $deliveryDestination,
            ['campaign', 'segment'],
            true
        )) {
            throw new InvalidArgumentException(
                'WhatsApp message delivery destination must be campaign or segment.'
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        if (null !== $id && $id > 0) {
            if (null === $this->find($id)) {
                throw new InvalidArgumentException(
                    'The WhatsApp message to update was not found.'
                );
            }

            $this->connection->update(
                MAUTIC_TABLE_PREFIX.self::TABLE,
                [
                    'name' => $name,
                    'content' => $content,
                    'asset_id' => $assetId,
                    'delivery_destination' => $deliveryDestination,
                    'is_published' => $isPublished ? 1 : 0,
                    'updated_at' => $now,
                ],
                ['id' => $id]
            );

            return $id;
        }

        $this->connection->insert(
            MAUTIC_TABLE_PREFIX.self::TABLE,
            [
                'name' => $name,
                'content' => $content,
                'asset_id' => $assetId,
                'delivery_destination' => $deliveryDestination,
                'is_published' => $isPublished ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'content' => (string) $row['content'],
            'asset_id' => null === $row['asset_id']
                ? null
                : (int) $row['asset_id'],
            'delivery_destination' => in_array(
                (string) ($row['delivery_destination'] ?? 'campaign'),
                ['campaign', 'segment'],
                true
            )
                ? (string) $row['delivery_destination']
                : 'campaign',
            'is_published' => 1 === (int) $row['is_published'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use Mautic\AssetBundle\Entity\Asset;
use Mautic\AssetBundle\Model\AssetModel;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use RuntimeException;

final class WhatsAppProviderEnvelopeBuilder
{
    private const MEDIA_MIME_PREFIXES = [
        'image/' => 'image',
        'audio/' => 'audio',
        'video/' => 'video',
    ];

    public function __construct(
        private WhatsAppMessageRepository $messageRepository,
        private AssetModel $assetModel,
        private CoreParametersHelper $coreParametersHelper
    ) {
    }

    /**
     * The content argument is the already-rendered queue content.
     *
     * @return array{
     *     type:string,
     *     message:string,
     *     whatsapp_message_id:?int,
     *     asset_id:?int,
     *     file_path:?string,
     *     file_name:?string,
     *     file_size:?int,
     *     mime:?string,
     *     extension:?string,
     *     media_type:?string,
     *     document_name:?string,
     *     document_type:?string
     * }
     */
    public function build(
        ?int $whatsappMessageId,
        string $renderedContent,
        ?int $directAssetId = null
    ): array {
        $assetId = $this->normalizePositiveId($directAssetId);

        if (null === $assetId) {
            if (
                null === $whatsappMessageId
                || $whatsappMessageId < 1
            ) {
                return $this->textEnvelope(
                    $renderedContent,
                    null
                );
            }

            $preparedMessage = $this->messageRepository->find(
                $whatsappMessageId
            );

            if (null === $preparedMessage) {
                throw new RuntimeException(
                    'prepared_whatsapp_message_not_found'
                );
            }

            $assetId = $this->normalizePositiveId(
                $preparedMessage['asset_id'] ?? null
            );

            if (null === $assetId) {
                return $this->textEnvelope(
                    $renderedContent,
                    $whatsappMessageId
                );
            }
        }

        $isDirectAsset = null !== $this->normalizePositiveId(
            $directAssetId
        );
        $asset = $this->assetModel->getEntity($assetId);

        if (!$asset instanceof Asset) {
            throw new RuntimeException(
                $isDirectAsset
                    ? 'queued_whatsapp_asset_not_found'
                    : 'prepared_whatsapp_asset_not_found'
            );
        }

        $storageLocation = strtolower(
            trim((string) $asset->getStorageLocation())
        );

        if ('' !== $storageLocation && 'local' !== $storageLocation) {
            throw new RuntimeException(
                $isDirectAsset
                    ? 'queued_whatsapp_asset_is_not_local'
                    : 'prepared_whatsapp_asset_is_not_local'
            );
        }

        $asset->setUploadDir(
            (string) $this->coreParametersHelper->get('upload_dir')
        );

        $filePath = $asset->getAbsolutePath();

        if (
            !is_string($filePath)
            || '' === $filePath
            || !is_file($filePath)
            || !is_readable($filePath)
        ) {
            throw new RuntimeException(
                $isDirectAsset
                    ? 'queued_whatsapp_asset_file_not_readable'
                    : 'prepared_whatsapp_asset_file_not_readable'
            );
        }

        $originalName = trim(
            (string) $asset->getOriginalFileName()
        );
        $extension = strtolower(
            trim((string) $asset->getExtension())
        );

        if ('' === $extension && '' !== $originalName) {
            $extension = strtolower(
                pathinfo($originalName, PATHINFO_EXTENSION)
            );
        }

        if ('' === $originalName) {
            $originalName = basename($filePath);
        }

        $mime = strtolower(trim((string) $asset->getMime()));
        $fileSize = filesize($filePath);
        $normalizedSize = false === $fileSize
            ? null
            : (int) $fileSize;
        $mediaType = $this->detectMediaType($mime);

        if (null !== $mediaType) {
            return [
                'type' => 'media',
                'message' => $renderedContent,
                'whatsapp_message_id' => $whatsappMessageId,
                'asset_id' => $assetId,
                'file_path' => $filePath,
                'file_name' => $originalName,
                'file_size' => $normalizedSize,
                'mime' => '' === $mime ? null : $mime,
                'extension' => '' === $extension
                    ? null
                    : $extension,
                'media_type' => $mediaType,
                'document_name' => null,
                'document_type' => null,
            ];
        }

        return [
            'type' => 'document',
            'message' => $renderedContent,
            'whatsapp_message_id' => $whatsappMessageId,
            'asset_id' => $assetId,
            'file_path' => $filePath,
            'file_name' => $originalName,
            'file_size' => $normalizedSize,
            'mime' => '' === $mime ? null : $mime,
            'extension' => '' === $extension
                ? null
                : $extension,
            'media_type' => null,
            'document_name' => $originalName,
            'document_type' => '' !== $extension
                ? $extension
                : ('' === $mime ? null : $mime),
        ];
    }

    /**
     * @return array{
     *     type:string,
     *     message:string,
     *     whatsapp_message_id:?int,
     *     asset_id:null,
     *     file_path:null,
     *     file_name:null,
     *     file_size:null,
     *     mime:null,
     *     extension:null,
     *     media_type:null,
     *     document_name:null,
     *     document_type:null
     * }
     */
    private function textEnvelope(
        string $renderedContent,
        ?int $whatsappMessageId
    ): array {
        return [
            'type' => 'text',
            'message' => $renderedContent,
            'whatsapp_message_id' => $whatsappMessageId,
            'asset_id' => null,
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'mime' => null,
            'extension' => null,
            'media_type' => null,
            'document_name' => null,
            'document_type' => null,
        ];
    }

    private function detectMediaType(string $mime): ?string
    {
        foreach (
            self::MEDIA_MIME_PREFIXES as $prefix => $mediaType
        ) {
            if (str_starts_with($mime, $prefix)) {
                return $mediaType;
            }
        }

        return null;
    }

    private function normalizePositiveId(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}

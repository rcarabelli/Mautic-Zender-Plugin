<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service;

use InvalidArgumentException;
use Mautic\AssetBundle\Entity\Asset;
use Mautic\AssetBundle\Model\AssetModel;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\FileHelper;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WhatsAppAssetUploadService
{
    public function __construct(
        private readonly AssetModel $assetModel,
        private readonly CoreParametersHelper $coreParametersHelper
    ) {
    }

    public function create(
        UploadedFile $uploadedFile,
        string $messageName
    ): Asset {
        if (!$uploadedFile->isValid()) {
            throw new InvalidArgumentException(
                'mautic.zender.whatsapp.messages.multimedia.invalid'
            );
        }

        $originalName = basename(
            trim($uploadedFile->getClientOriginalName())
        );
        $extension = strtolower(
            pathinfo($originalName, PATHINFO_EXTENSION)
        );
        $allowedExtensions = array_map(
            static fn (mixed $value): string => strtolower(
                trim((string) $value)
            ),
            (array) $this->coreParametersHelper->get(
                'allowed_extensions'
            )
        );

        if (
            '' === $originalName
            || '' === $extension
            || !in_array($extension, $allowedExtensions, true)
        ) {
            throw new InvalidArgumentException(
                'mautic.zender.whatsapp.messages.multimedia.invalid'
            );
        }

        $size = $uploadedFile->getSize();
        $maxSize = FileHelper::convertMegabytesToBytes(
            $this->assetModel->getMaxUploadSize('M', false)
        );

        if (
            false === $size
            || $size < 1
            || ($maxSize > 0 && $size > $maxSize)
        ) {
            throw new InvalidArgumentException(
                'mautic.zender.whatsapp.messages.multimedia.invalid'
            );
        }

        $asset = $this->assetModel->getEntity();

        if (!$asset instanceof Asset) {
            throw new InvalidArgumentException(
                'mautic.zender.whatsapp.messages.multimedia.invalid'
            );
        }

        $title = trim($messageName);
        $title = '' === $title
            ? $originalName
            : $title.' — '.$originalName;
        $locale = trim(
            (string) $this->coreParametersHelper->get('locale')
        );

        $asset->setTitle(mb_substr($title, 0, 191));
        $asset->setStorageLocation('local');
        $asset->setOriginalFileName($originalName);
        $asset->setLanguage('' !== $locale ? $locale : 'en_US');
        $asset->setIsPublished(true);
        $asset->setMaxSize(
            FileHelper::convertMegabytesToBytes(
                $this->coreParametersHelper->get('max_size')
            )
        );
        $asset->setUploadDir(
            (string) $this->coreParametersHelper->get('upload_dir')
        );
        $asset->setFile($uploadedFile);

        $stage = 'temp_id';

        try {
            $tempId = 'zender_whatsapp_direct_'
                .bin2hex(random_bytes(12));
            $asset->setTempId($tempId);

            $stage = 'pre_upload';
            $asset->preUpload();

            $stage = 'upload';
            $asset->upload();

            $stage = 'save_entity';
            $asset->setDateModified(new \DateTime());
            $this->assetModel->saveEntity($asset);
        } catch (\Throwable $exception) {
            try {
                $asset->removeUpload(false);
            } catch (\Throwable) {
                // Preserve the original direct-upload exception.
            }

            error_log(sprintf(
                '[MauticZenderBundle] direct multimedia upload failed; '
                .'stage=%s; exception_class=%s',
                $stage,
                get_class($exception)
            ));

            throw new InvalidArgumentException(
                'mautic.zender.whatsapp.messages.multimedia.invalid',
                0,
                $exception
            );
        }

        if ((int) $asset->getId() < 1) {
            try {
                $asset->removeUpload(false);
            } catch (\Throwable) {
                // Preserve the missing Asset ID failure.
            }

            throw new InvalidArgumentException(
                'mautic.zender.whatsapp.messages.multimedia.invalid'
            );
        }

        return $asset;
    }

    public function remove(?Asset $asset): void
    {
        if (null === $asset || (int) $asset->getId() < 1) {
            return;
        }

        try {
            $this->assetModel->deleteEntity($asset);
        } catch (\Throwable) {
            // Preserve the original message persistence error.
        }
    }
}

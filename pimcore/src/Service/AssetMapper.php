<?php

declare(strict_types=1);

namespace App\Service;

use Pimcore\Model\Asset\Image;

class AssetMapper
{
    public function __construct(private readonly string $pimcorePublicUrl)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toShopwareMediaPayload(Image $asset): array
    {
        $id = $asset->getId();
        if ($id === null) {
            throw new \InvalidArgumentException('Asset has no ID — cannot build Shopware media payload.');
        }

        return [
            'id' => md5((string) $id),
            'private' => false,
            'title' => $asset->getFilename(),
        ];
    }

    public function getAssetPublicUrl(Image $asset): string
    {
        return rtrim($this->pimcorePublicUrl, '/') . $asset->getFullPath();
    }
}

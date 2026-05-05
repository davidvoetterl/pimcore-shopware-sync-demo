<?php

declare(strict_types=1);

namespace App\Service;

use Pimcore\Model\Asset\Image;
use Pimcore\Model\DataObject\Product;

class ProductMapper
{
    public function __construct(private readonly ShopwareApiClient $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toShopwarePayload(Product $product): array
    {
        $sku = $product->getSku();
        if ($sku === null || $sku === '') {
            throw new \InvalidArgumentException(sprintf('Product #%d has no SKU', $product->getId()));
        }

        $price = (float) $product->getPrice();

        $payload = [
            'id' => $this->deterministicId($sku),
            'productNumber' => $sku,
            'name' => $product->getName() ?? $sku,
            'description' => $product->getDescription(),
            'stock' => (int) ($product->getStock() ?? 0),
            'taxId' => $this->fetchTaxId(),
            'price' => [[
                'currencyId' => $this->fetchDefaultCurrencyId(),
                'gross' => $price,
                'net' => $price,
                'linked' => false,
            ]],
        ];

        $cover = $this->mapCoverImage($product);
        if ($cover !== null) {
            $payload['cover'] = $cover;
        }

        return $payload;
    }

    private function mapCoverImage(Product $product): ?array
    {
        $image = $product->getImage();
        if (!$image instanceof Image) {
            return null;
        }

        return ['mediaId' => md5((string) $image->getId())];
    }

    private function deterministicId(string $sku): string
    {
        return md5($sku);
    }

    private function fetchTaxId(): string
    {
        $response = $this->client->request('POST', 'api/search/tax', ['limit' => 1]);
        $taxes = $response['data'] ?? [];
        if ($taxes === []) {
            throw new \RuntimeException('No tax records found in Shopware.');
        }
        return $taxes[0]['id'];
    }

    private function fetchDefaultCurrencyId(): string
    {
        $response = $this->client->request('POST', 'api/search/currency', [
            'filter' => [['type' => 'equals', 'field' => 'isoCode', 'value' => 'EUR']],
            'limit' => 1,
        ]);
        $currencies = $response['data'] ?? [];
        if ($currencies === []) {
            throw new \RuntimeException('EUR currency not found in Shopware.');
        }
        return $currencies[0]['id'];
    }
}

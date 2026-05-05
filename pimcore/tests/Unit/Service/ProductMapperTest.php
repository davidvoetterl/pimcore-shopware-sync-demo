<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ProductMapper;
use App\Service\ShopwareApiClient;
use Codeception\Test\Unit;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\DataObject\Product;

class ProductMapperTest extends Unit
{
    private ShopwareApiClient $client;
    private ProductMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(ShopwareApiClient::class);
        $this->client->method('request')->willReturnMap([
            ['POST', 'api/search/tax', ['limit' => 1], ['data' => [['id' => 'tax-uuid-123']]]],
            ['POST', 'api/search/currency', [
                'filter' => [['type' => 'equals', 'field' => 'isoCode', 'value' => 'EUR']],
                'limit' => 1,
            ], ['data' => [['id' => 'currency-uuid-456']]]],
        ]);

        $this->mapper = new ProductMapper($this->client);
    }

    public function testMapsAllFieldsCorrectly(): void
    {
        $product = $this->makeProduct(
            id: 42,
            sku: 'shoes-001',
            name: 'Running Shoes',
            description: 'Lightweight',
            price: 89.99,
            stock: 10.0,
        );

        $payload = $this->mapper->toShopwarePayload($product);

        self::assertSame(md5('shoes-001'), $payload['id']);
        self::assertSame('shoes-001', $payload['productNumber']);
        self::assertSame('Running Shoes', $payload['name']);
        self::assertSame('Lightweight', $payload['description']);
        self::assertSame(10, $payload['stock']);
        self::assertSame('tax-uuid-123', $payload['taxId']);
        self::assertSame('currency-uuid-456', $payload['price'][0]['currencyId']);
        self::assertSame(89.99, $payload['price'][0]['gross']);
        self::assertSame(89.99, $payload['price'][0]['net']);
        self::assertFalse($payload['price'][0]['linked']);
    }

    public function testUsesSKUAsNameFallback(): void
    {
        $product = $this->makeProduct(id: 1, sku: 'fallback-sku', name: null);

        $payload = $this->mapper->toShopwarePayload($product);

        self::assertSame('fallback-sku', $payload['name']);
    }

    public function testZeroStockWhenNull(): void
    {
        $product = $this->makeProduct(id: 1, sku: 'test', stock: null, price: 0.0);

        $payload = $this->mapper->toShopwarePayload($product);

        self::assertSame(0, $payload['stock']);
    }

    public function testThrowsOnMissingSKU(): void
    {
        $product = $this->makeProduct(id: 1, sku: '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Product #1 has no SKU');

        $this->mapper->toShopwarePayload($product);
    }

    public function testThrowsOnNullSKU(): void
    {
        $product = $this->makeProduct(id: 5, sku: null);

        $this->expectException(\InvalidArgumentException::class);

        $this->mapper->toShopwarePayload($product);
    }

    public function testCoverIsMappedWhenImageIsSet(): void
    {
        $image = $this->createMock(Image::class);
        $image->method('getId')->willReturn(42);

        $product = $this->makeProduct(id: 1, sku: 'cover-test', image: $image);

        $payload = $this->mapper->toShopwarePayload($product);

        self::assertArrayHasKey('cover', $payload);
        self::assertSame(md5('42'), $payload['cover']['mediaId']);
    }

    public function testCoverIsAbsentWhenImageIsNull(): void
    {
        $product = $this->makeProduct(id: 1, sku: 'no-cover', image: null);

        $payload = $this->mapper->toShopwarePayload($product);

        self::assertArrayNotHasKey('cover', $payload);
    }

    // ------------------------------------------------------------------

    private function makeProduct(
        int $id,
        ?string $sku = 'test-sku',
        ?string $name = 'Test Product',
        ?string $description = null,
        float $price = 0.0,
        ?float $stock = 0.0,
        ?Image $image = null,
    ): Product {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn($name);
        $product->method('getDescription')->willReturn($description);
        $product->method('getPrice')->willReturn((string) $price);
        $product->method('getStock')->willReturn($stock);
        $product->method('getImage')->willReturn($image);
        return $product;
    }
}

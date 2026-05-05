<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\Operation;
use App\Message\SyncProductMessage;
use App\MessageHandler\SyncProductHandler;
use App\Service\ProductMapper;
use App\Service\ShopwareApiClient;
use Codeception\Test\Unit;
use Pimcore\Model\DataObject\Product;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class SyncProductHandlerTest extends Unit
{
    private ShopwareApiClient $client;
    private ProductMapper $mapper;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(ShopwareApiClient::class);
        $this->mapper = $this->createMock(ProductMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    // --- Upsert path ---

    public function testUpsertSyncsProductToShopware(): void
    {
        $product = $this->mockProduct(id: 7, sku: 'shoes-001');
        $expectedPayload = ['id' => md5('shoes-001'), 'productNumber' => 'shoes-001'];

        $this->mapper->method('toShopwarePayload')->with($product)->willReturn($expectedPayload);

        $this->client->expects(self::once())
            ->method('request')
            ->with('POST', 'api/_action/sync', [
                'sync-products' => [
                    'entity' => 'product',
                    'action' => 'upsert',
                    'payload' => [$expectedPayload],
                ],
            ]);

        $this->logger->expects(self::once())->method('info');

        $handler = $this->makeHandler(foundProduct: $product);
        ($handler)(new SyncProductMessage(productId: 7, operation: Operation::Upsert, sku: 'shoes-001'));
    }

    public function testUpsertThrowsUnrecoverableWhenProductNotFound(): void
    {
        $this->client->expects(self::never())->method('request');

        $handler = $this->makeHandler(foundProduct: null);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($handler)(new SyncProductMessage(productId: 99, operation: Operation::Upsert));
    }

    public function testUpsertThrowsUnrecoverableOnInvalidArgumentFromMapper(): void
    {
        $product = $this->mockProduct(id: 7, sku: '');

        $this->mapper->method('toShopwarePayload')
            ->willThrowException(new \InvalidArgumentException('Product #7 has no SKU'));

        $this->client->expects(self::never())->method('request');

        $handler = $this->makeHandler(foundProduct: $product);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($handler)(new SyncProductMessage(productId: 7, operation: Operation::Upsert, sku: ''));
    }

    // --- Delete path ---

    public function testDeleteSendsDeletePayloadToShopware(): void
    {
        $this->client->expects(self::once())
            ->method('request')
            ->with('POST', 'api/_action/sync', [
                'sync-products' => [
                    'entity' => 'product',
                    'action' => 'delete',
                    'payload' => [['id' => md5('shoes-001')]],
                ],
            ]);

        $this->logger->expects(self::once())->method('info');

        $handler = $this->makeHandler(foundProduct: null);
        ($handler)(new SyncProductMessage(productId: 7, operation: Operation::Delete, sku: 'shoes-001'));
    }

    public function testDeleteThrowsUnrecoverableWhenSkuMissing(): void
    {
        $this->client->expects(self::never())->method('request');

        $handler = $this->makeHandler(foundProduct: null);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($handler)(new SyncProductMessage(productId: 7, operation: Operation::Delete, sku: null));
    }

    // ------------------------------------------------------------------

    private function makeHandler(?Product $foundProduct): SyncProductHandler
    {
        $handler = $this->getMockBuilder(SyncProductHandler::class)
            ->setConstructorArgs([$this->client, $this->mapper, $this->logger])
            ->onlyMethods(['findProduct'])
            ->getMock();

        $handler->method('findProduct')->willReturn($foundProduct);

        return $handler;
    }

    private function mockProduct(int $id, string $sku): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        return $product;
    }
}

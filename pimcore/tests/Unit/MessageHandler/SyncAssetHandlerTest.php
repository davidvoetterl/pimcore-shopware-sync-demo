<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\Operation;
use App\Message\SyncAssetMessage;
use App\MessageHandler\SyncAssetHandler;
use App\Service\AssetMapper;
use App\Service\ShopwareApiClient;
use Codeception\Test\Unit;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class SyncAssetHandlerTest extends Unit
{
    private ShopwareApiClient $client;
    private AssetMapper $mapper;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(ShopwareApiClient::class);
        $this->mapper = $this->createMock(AssetMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    // --- Upsert path ---

    public function testUpsertSyncsMediaEntityAndUploadsFile(): void
    {
        $image = $this->mockImage(id: 5, fileName: 'shoes.jpg');
        $mediaPayload = ['id' => md5('5'), 'private' => false, 'title' => 'shoes.jpg'];

        $this->mapper->method('toShopwareMediaPayload')->with($image)->willReturn($mediaPayload);
        $this->mapper->method('getAssetPublicUrl')->with($image)->willReturn('http://pimcore.local/shoes.jpg');

        $this->client->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $endpoint, array $payload) use ($mediaPayload) {
                if (str_contains($endpoint, '_action/sync')) {
                    self::assertSame('POST', $method);
                    self::assertSame('sync-media', array_key_first($payload));
                    self::assertSame('upsert', $payload['sync-media']['action']);
                    self::assertSame([$mediaPayload], $payload['sync-media']['payload']);
                } else {
                    // upload endpoint
                    self::assertStringContainsString('_action/media/', $endpoint);
                    self::assertStringContainsString('extension=jpg', $endpoint);
                    self::assertStringContainsString('fileName=shoes.jpg', $endpoint);
                    self::assertSame(['url' => 'http://pimcore.local/shoes.jpg'], $payload);
                }
                return [];
            });

        $this->logger->expects(self::once())->method('info');

        $handler = $this->makeHandler(foundAsset: $image);
        ($handler)(new SyncAssetMessage(assetId: 5, operation: Operation::Upsert, fileName: 'shoes.jpg'));
    }

    public function testUpsertThrowsUnrecoverableWhenAssetNotFound(): void
    {
        $this->client->expects(self::never())->method('request');

        $handler = $this->makeHandler(foundAsset: null);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($handler)(new SyncAssetMessage(assetId: 99, operation: Operation::Upsert));
    }

    public function testUpsertThrowsUnrecoverableOnInvalidArgumentFromMapper(): void
    {
        $image = $this->mockImage(id: 5, fileName: 'shoes.jpg');

        $this->mapper->method('toShopwareMediaPayload')
            ->willThrowException(new \InvalidArgumentException('Asset has no ID'));

        $this->client->expects(self::never())->method('request');

        $handler = $this->makeHandler(foundAsset: $image);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($handler)(new SyncAssetMessage(assetId: 5, operation: Operation::Upsert, fileName: 'shoes.jpg'));
    }

    public function testUploadFailureIsLoggedButNotPropagated(): void
    {
        $image = $this->mockImage(id: 5, fileName: 'shoes.jpg');
        $mediaPayload = ['id' => md5('5'), 'private' => false, 'title' => 'shoes.jpg'];

        $this->mapper->method('toShopwareMediaPayload')->willReturn($mediaPayload);
        $this->mapper->method('getAssetPublicUrl')->willReturn('http://pimcore.local/shoes.jpg');

        $callCount = 0;
        $this->client->method('request')
            ->willReturnCallback(function (string $method, string $endpoint) use (&$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    throw new \RuntimeException('Shopware media upload failed');
                }
                return [];
            });

        $this->logger->expects(self::once())->method('warning');
        $this->logger->expects(self::once())->method('info');

        $handler = $this->makeHandler(foundAsset: $image);
        // must NOT throw
        ($handler)(new SyncAssetMessage(assetId: 5, operation: Operation::Upsert, fileName: 'shoes.jpg'));
    }

    // --- Delete path ---

    public function testDeleteSendsDeletePayloadToShopware(): void
    {
        $expectedMediaId = md5('5');

        $this->client->expects(self::once())
            ->method('request')
            ->with('POST', 'api/_action/sync', [
                'sync-media' => [
                    'entity' => 'media',
                    'action' => 'delete',
                    'payload' => [['id' => $expectedMediaId]],
                ],
            ]);

        $this->logger->expects(self::once())->method('info');

        $handler = $this->makeHandler(foundAsset: null);
        ($handler)(new SyncAssetMessage(assetId: 5, operation: Operation::Delete, fileName: 'shoes.jpg'));
    }

    public function testDeleteThrowsUnrecoverableWhenFileNameMissing(): void
    {
        $this->client->expects(self::never())->method('request');

        $handler = $this->makeHandler(foundAsset: null);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($handler)(new SyncAssetMessage(assetId: 5, operation: Operation::Delete, fileName: null));
    }

    // ------------------------------------------------------------------

    private function makeHandler(?Asset $foundAsset): SyncAssetHandler
    {
        $handler = $this->getMockBuilder(SyncAssetHandler::class)
            ->setConstructorArgs([$this->client, $this->mapper, $this->logger])
            ->onlyMethods(['findAsset'])
            ->getMock();

        $handler->method('findAsset')->willReturn($foundAsset);

        return $handler;
    }

    private function mockImage(int $id, string $fileName): Image
    {
        $image = $this->createMock(Image::class);
        $image->method('getId')->willReturn($id);
        $image->method('getFilename')->willReturn($fileName);
        return $image;
    }
}

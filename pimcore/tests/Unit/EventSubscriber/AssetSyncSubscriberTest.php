<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\AssetSyncSubscriber;
use App\Message\Operation;
use App\Message\SyncAssetMessage;
use Codeception\Test\Unit;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class AssetSyncSubscriberTest extends Unit
{
    private MessageBusInterface $bus;
    private AssetSyncSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->subscriber = new AssetSyncSubscriber($this->bus);
    }

    public function testSubscribesToCorrectEvents(): void
    {
        $events = AssetSyncSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(AssetEvents::POST_ADD, $events);
        self::assertArrayHasKey(AssetEvents::POST_UPDATE, $events);
        self::assertArrayHasKey(AssetEvents::POST_DELETE, $events);
    }

    public function testDispatchesUpsertMessageOnImageSave(): void
    {
        $image = $this->mockImage(id: 5, fileName: 'shoes.jpg');
        $event = new AssetEvent($image);

        $this->bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (SyncAssetMessage $msg) {
                return $msg->assetId === 5
                    && $msg->fileName === 'shoes.jpg'
                    && $msg->operation === Operation::Upsert;
            }))
            ->willReturn(new Envelope(new SyncAssetMessage(5)));

        $this->subscriber->onAssetChange($event);
    }

    public function testIgnoresNonImageAssets(): void
    {
        $document = $this->createMock(Asset\Document::class);
        $event = new AssetEvent($document);

        $this->bus->expects(self::never())->method('dispatch');

        $this->subscriber->onAssetChange($event);
    }

    public function testIgnoresAutoSaveEvents(): void
    {
        $image = $this->mockImage(id: 5, fileName: 'shoes.jpg');
        $event = new AssetEvent($image, ['isAutoSave' => true]);

        $this->bus->expects(self::never())->method('dispatch');

        $this->subscriber->onAssetChange($event);
    }

    public function testIgnoresSaveVersionOnlyEvents(): void
    {
        $image = $this->mockImage(id: 5, fileName: 'shoes.jpg');
        $event = new AssetEvent($image, ['saveVersionOnly' => true]);

        $this->bus->expects(self::never())->method('dispatch');

        $this->subscriber->onAssetChange($event);
    }

    public function testDispatchesDeleteMessageOnImageDelete(): void
    {
        $image = $this->mockImage(id: 5, fileName: 'shoes.jpg');
        $event = new AssetEvent($image);

        $this->bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (SyncAssetMessage $msg) {
                return $msg->assetId === 5
                    && $msg->fileName === 'shoes.jpg'
                    && $msg->operation === Operation::Delete;
            }))
            ->willReturn(new Envelope(new SyncAssetMessage(5)));

        $this->subscriber->onAssetDelete($event);
    }

    public function testDeleteIgnoresNonImageAssets(): void
    {
        $document = $this->createMock(Asset\Document::class);
        $event = new AssetEvent($document);

        $this->bus->expects(self::never())->method('dispatch');

        $this->subscriber->onAssetDelete($event);
    }

    // ------------------------------------------------------------------

    private function mockImage(int $id, string $fileName): Image
    {
        $image = $this->createMock(Image::class);
        $image->method('getId')->willReturn($id);
        $image->method('getFilename')->willReturn($fileName);
        return $image;
    }
}

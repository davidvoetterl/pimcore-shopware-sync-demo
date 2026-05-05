<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ProductSyncSubscriber;
use App\Message\Operation;
use App\Message\SyncProductMessage;
use Codeception\Test\Unit;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Product;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class ProductSyncSubscriberTest extends Unit
{
    private MessageBusInterface $bus;
    private ProductSyncSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->subscriber = new ProductSyncSubscriber($this->bus);
    }

    public function testSubscribesToCorrectEvents(): void
    {
        $events = ProductSyncSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(DataObjectEvents::POST_ADD, $events);
        self::assertArrayHasKey(DataObjectEvents::POST_UPDATE, $events);
        self::assertArrayHasKey(DataObjectEvents::POST_DELETE, $events);
    }

    public function testDispatchesUpsertMessageOnProductSave(): void
    {
        $product = $this->mockProduct(id: 7, sku: 'shoes-001');
        $event = new DataObjectEvent($product);

        $this->bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (SyncProductMessage $msg) {
                return $msg->productId === 7
                    && $msg->sku === 'shoes-001'
                    && $msg->operation === Operation::Upsert;
            }))
            ->willReturn(new Envelope(new SyncProductMessage(7)));

        $this->subscriber->onProductChange($event);
    }

    public function testIgnoresNonProductObjects(): void
    {
        $other = $this->createMock(Concrete::class);
        $event = new DataObjectEvent($other);

        $this->bus->expects(self::never())->method('dispatch');

        $this->subscriber->onProductChange($event);
    }

    public function testIgnoresAutoSaveEvents(): void
    {
        $product = $this->mockProduct(id: 7, sku: 'shoes-001');
        $event = new DataObjectEvent($product, ['isAutoSave' => true]);

        $this->bus->expects(self::never())->method('dispatch');

        $this->subscriber->onProductChange($event);
    }

    public function testIgnoresSaveVersionOnlyEvents(): void
    {
        $product = $this->mockProduct(id: 7, sku: 'shoes-001');
        $event = new DataObjectEvent($product, ['saveVersionOnly' => true]);

        $this->bus->expects(self::never())->method('dispatch');

        $this->subscriber->onProductChange($event);
    }

    public function testDispatchesDeleteMessageOnProductDelete(): void
    {
        $product = $this->mockProduct(id: 7, sku: 'shoes-001');
        $event = new DataObjectEvent($product);

        $this->bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (SyncProductMessage $msg) {
                return $msg->productId === 7
                    && $msg->sku === 'shoes-001'
                    && $msg->operation === Operation::Delete;
            }))
            ->willReturn(new Envelope(new SyncProductMessage(7)));

        $this->subscriber->onProductDelete($event);
    }

    public function testDeleteIgnoresNonProductObjects(): void
    {
        $other = $this->createMock(Concrete::class);
        $event = new DataObjectEvent($other);

        $this->bus->expects(self::never())->method('dispatch');

        $this->subscriber->onProductDelete($event);
    }

    // ------------------------------------------------------------------

    private function mockProduct(int $id, string $sku): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        return $product;
    }
}

<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Message\Operation;
use App\Message\SyncProductMessage;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Product;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProductSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectEvents::POST_ADD => 'onProductChange',
            DataObjectEvents::POST_UPDATE => 'onProductChange',
            DataObjectEvents::POST_DELETE => 'onProductDelete',
        ];
    }

    public function onProductChange(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        if (!$object instanceof Product) {
            return;
        }

        // Pimcore fires POST_UPDATE multiple times per real save: once for
        // saveVersion() (auto-save while typing) and once for the actual update.
        // We only sync on real saves, not version-only writes or auto-saves.
        if ($event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly') === true) {
            return;
        }
        if ($event->hasArgument('isAutoSave') && $event->getArgument('isAutoSave') === true) {
            return;
        }

        $this->bus->dispatch(new SyncProductMessage(
            productId: (int) $object->getId(),
            operation: Operation::Upsert,
            sku: $object->getSku(),
        ));
    }

    public function onProductDelete(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        if (!$object instanceof Product) {
            return;
        }

        $this->bus->dispatch(new SyncProductMessage(
            productId: (int) $object->getId(),
            operation: Operation::Delete,
            sku: $object->getSku(),
        ));
    }
}

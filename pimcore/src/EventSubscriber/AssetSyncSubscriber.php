<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Message\Operation;
use App\Message\SyncAssetMessage;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Model\Asset\Image;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class AssetSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AssetEvents::POST_ADD => 'onAssetChange',
            AssetEvents::POST_UPDATE => 'onAssetChange',
            AssetEvents::POST_DELETE => 'onAssetDelete',
        ];
    }

    public function onAssetChange(AssetEvent $event): void
    {
        $asset = $event->getAsset();
        if (!$asset instanceof Image) {
            return;
        }

        if ($event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly') === true) {
            return;
        }
        if ($event->hasArgument('isAutoSave') && $event->getArgument('isAutoSave') === true) {
            return;
        }

        $this->bus->dispatch(new SyncAssetMessage(
            assetId: (int) $asset->getId(),
            operation: Operation::Upsert,
            fileName: $asset->getFilename(),
        ));
    }

    public function onAssetDelete(AssetEvent $event): void
    {
        $asset = $event->getAsset();
        if (!$asset instanceof Image) {
            return;
        }

        $this->bus->dispatch(new SyncAssetMessage(
            assetId: (int) $asset->getId(),
            operation: Operation::Delete,
            fileName: $asset->getFilename(),
        ));
    }
}

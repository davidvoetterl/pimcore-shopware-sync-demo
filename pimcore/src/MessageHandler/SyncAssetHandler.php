<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\Operation;
use App\Message\SyncAssetMessage;
use App\Service\AssetMapper;
use App\Service\ShopwareApiClient;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
class SyncAssetHandler
{
    public function __construct(
        private readonly ShopwareApiClient $client,
        private readonly AssetMapper $mapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncAssetMessage $message): void
    {
        if ($message->operation === Operation::Delete) {
            $this->handleDelete($message);
            return;
        }

        $asset = $this->findAsset($message->assetId);
        if (!$asset instanceof Image) {
            throw new UnrecoverableMessageHandlingException(
                sprintf('Asset #%d not found or not an Image — skipping sync.', $message->assetId),
            );
        }

        try {
            $mediaPayload = $this->mapper->toShopwareMediaPayload($asset);
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        $this->client->request('POST', 'api/_action/sync', [
            'sync-media' => [
                'entity' => 'media',
                'action' => 'upsert',
                'payload' => [$mediaPayload],
            ],
        ]);

        $filename = $asset->getFilename() ?? '';
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $uploadEndpoint = sprintf(
            'api/_action/media/%s/upload?extension=%s&fileName=%s',
            $mediaPayload['id'],
            urlencode($extension),
            urlencode($filename),
        );

        try {
            $this->client->request('POST', $uploadEndpoint, [
                'url' => $this->mapper->getAssetPublicUrl($asset),
            ]);
        } catch (\Throwable $e) {
            // Media entity exists; file upload failure is non-fatal — a re-queue or later save will retry.
            $this->logger->warning('Asset file upload to Shopware failed', [
                'assetId' => $asset->getId(),
                'fileName' => $filename,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('Synced asset to Shopware', [
            'assetId' => $asset->getId(),
            'fileName' => $filename,
        ]);
    }

    protected function findAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }

    private function handleDelete(SyncAssetMessage $message): void
    {
        if ($message->fileName === null || $message->fileName === '') {
            throw new UnrecoverableMessageHandlingException(
                sprintf('Cannot delete asset #%d in Shopware: fileName missing in message.', $message->assetId),
            );
        }

        $mediaId = md5((string) $message->assetId);

        $this->client->request('POST', 'api/_action/sync', [
            'sync-media' => [
                'entity' => 'media',
                'action' => 'delete',
                'payload' => [['id' => $mediaId]],
            ],
        ]);

        $this->logger->info('Deleted media in Shopware', [
            'assetId' => $message->assetId,
            'fileName' => $message->fileName,
        ]);
    }
}

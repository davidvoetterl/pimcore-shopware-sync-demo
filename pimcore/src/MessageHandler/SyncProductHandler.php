<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\Operation;
use App\Message\SyncProductMessage;
use App\Service\ProductMapper;
use App\Service\ShopwareApiClient;
use Pimcore\Model\DataObject\Product;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class SyncProductHandler
{
    public function __construct(
        private readonly ShopwareApiClient $client,
        private readonly ProductMapper $mapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncProductMessage $message): void
    {
        if ($message->operation === Operation::Delete) {
            $this->handleDelete($message);
            return;
        }

        $product = Product::getById($message->productId);
        if (!$product instanceof Product) {
            throw new UnrecoverableMessageHandlingException(
                sprintf('Product #%d not found — skipping sync.', $message->productId),
            );
        }

        try {
            $payload = $this->mapper->toShopwarePayload($product);
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        $this->client->request('POST', 'api/_action/sync', [
            'sync-products' => [
                'entity' => 'product',
                'action' => 'upsert',
                'payload' => [$payload],
            ],
        ]);

        $this->logger->info('Synced product to Shopware', [
            'productId' => $product->getId(),
            'sku' => $product->getSku(),
        ]);
    }

    private function handleDelete(SyncProductMessage $message): void
    {
        if ($message->sku === null || $message->sku === '') {
            throw new UnrecoverableMessageHandlingException(
                sprintf('Cannot delete product #%d in Shopware: SKU missing in message.', $message->productId),
            );
        }

        $shopwareId = md5($message->sku);

        $this->client->request('POST', 'api/_action/sync', [
            'sync-products' => [
                'entity' => 'product',
                'action' => 'delete',
                'payload' => [['id' => $shopwareId]],
            ],
        ]);

        $this->logger->info('Deleted product in Shopware', [
            'productId' => $message->productId,
            'sku' => $message->sku,
        ]);
    }
}

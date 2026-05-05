<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ShopwareApiClient;
use Pimcore\Model\DataObject\Product;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:products',
    description: 'Sync Pimcore Product data objects to Shopware via the Admin API',
)]
class SyncProductsCommand extends Command
{
    public function __construct(private readonly ShopwareApiClient $client)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $taxId = $this->fetchTaxId();
        $currencyId = $this->fetchDefaultCurrencyId();
        $io->writeln(sprintf('<info>Using taxId=%s, currencyId=%s</info>', $taxId, $currencyId));

        $listing = Product::getList();
        $listing->setUnpublished(true);
        $products = $listing->load();

        if ($products === []) {
            $io->warning('No Product objects found in Pimcore.');
            return Command::SUCCESS;
        }

        $payload = [];
        foreach ($products as $product) {
            $sku = $product->getSku();
            if ($sku === null || $sku === '') {
                $io->warning(sprintf('Skipping product #%d: missing SKU', $product->getId()));
                continue;
            }

            $price = (float) $product->getPrice();

            $payload[] = [
                'id' => md5($sku),
                'productNumber' => $sku,
                'name' => $product->getName() ?? $sku,
                'description' => $product->getDescription(),
                'stock' => (int) ($product->getStock() ?? 0),
                'taxId' => $taxId,
                'price' => [[
                    'currencyId' => $currencyId,
                    'gross' => $price,
                    'net' => $price,
                    'linked' => false,
                ]],
            ];
        }

        if ($payload === []) {
            $io->warning('No syncable products.');
            return Command::SUCCESS;
        }

        $this->client->request('POST', 'api/_action/sync', [
            'sync-products' => [
                'entity' => 'product',
                'action' => 'upsert',
                'payload' => $payload,
            ],
        ]);

        $io->success(sprintf('Synced %d products to Shopware.', count($payload)));
        return Command::SUCCESS;
    }

    private function fetchTaxId(): string
    {
        $response = $this->client->request('POST', 'api/search/tax', ['limit' => 1]);
        $taxes = $response['data'] ?? [];

        if ($taxes === []) {
            throw new \RuntimeException('No tax records found in Shopware. Create at least one tax in the admin first.');
        }

        return $taxes[0]['id'];
    }

    private function fetchDefaultCurrencyId(): string
    {
        $response = $this->client->request('POST', 'api/search/currency', [
            'filter' => [
                ['type' => 'equals', 'field' => 'isoCode', 'value' => 'EUR'],
            ],
            'limit' => 1,
        ]);
        $currencies = $response['data'] ?? [];

        if ($currencies === []) {
            throw new \RuntimeException('EUR currency not found in Shopware.');
        }

        return $currencies[0]['id'];
    }
}

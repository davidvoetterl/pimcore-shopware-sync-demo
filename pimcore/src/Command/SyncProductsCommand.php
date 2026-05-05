<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Operation;
use App\Message\SyncProductMessage;
use Pimcore\Model\DataObject\Product;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:sync:products',
    description: 'Backfill: dispatch a SyncProductMessage for every Pimcore Product (initial load / re-sync).',
)]
class SyncProductsCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Only dispatch the first N products')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List products that would be dispatched, without enqueueing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $input->getOption('limit');

        $listing = Product::getList();
        $listing->setUnpublished(true);
        if ($limit !== null) {
            $listing->setLimit((int) $limit);
        }

        $count = 0;
        foreach ($listing as $product) {
            $sku = $product->getSku();
            if ($sku === null || $sku === '') {
                $io->warning(sprintf('Skipping product #%d: missing SKU', $product->getId()));
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('  would dispatch product #%d (sku=%s)', $product->getId(), $sku));
            } else {
                $this->bus->dispatch(new SyncProductMessage(
                    productId: (int) $product->getId(),
                    operation: Operation::Upsert,
                    sku: $sku,
                ));
            }
            $count++;
        }

        $io->success(sprintf(
            $dryRun ? 'Dry run: %d products would be dispatched.' : 'Dispatched %d sync messages. Worker will process them.',
            $count,
        ));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Message;

final class SyncProductMessage
{
    public function __construct(
        public readonly int $productId,
        public readonly Operation $operation = Operation::Upsert,
        /**
         * Captured at dispatch time — the handler needs it for deletes
         * because the Pimcore object is gone by the time the worker runs.
         */
        public readonly ?string $sku = null,
    ) {
    }
}
